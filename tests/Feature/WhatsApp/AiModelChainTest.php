<?php

use App\Enums\CustomerReplyIntent;
use App\Enums\Platform\PlatformRole;
use App\Exceptions\AI\AiRequestFailedException;
use App\Exceptions\AI\ClassificationFailedException;
use App\Filament\Pages\AiModels;
use App\Models\AiModelKey;
use App\Models\PlatformAuditLog;
use App\Services\AI\Chat\AiModelSettings;
use App\Services\AI\Chat\ChatClient;
use App\Services\AI\Chat\ChatModelChain;
use App\Services\AI\Chat\ChatRequest;
use App\Services\AI\Data\CustomerConversationContext;
use App\Services\AI\Providers\ModelChainReplyClassifier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const GEMINI = 'generativelanguage.googleapis.com/*';
const GROQ = 'api.groq.com/*';
const OPENROUTER = 'openrouter.ai/*';

beforeEach(function () {
    config([
        'ai.chain' => ['gemini', 'groq', 'openrouter', 'ollama', 'openai'],
        'ai.models.gemini.api_key' => 'gemini-key',
        'ai.models.groq.api_key' => 'groq-key',
        'ai.models.openrouter.api_key' => 'openrouter-key',
        'ai.models.ollama.base_url' => null,
        'ai.models.openai.api_key' => null,
    ]);
});

function answer(string $content, string $model = 'served-model', string $finish = 'stop'): array
{
    return ['model' => $model, 'choices' => [['finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content]]]];
}

function ask(): string
{
    return app(ChatClient::class)->complete(new ChatRequest([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hi']]))->provider;
}

function classification(): string
{
    return json_encode(['intent' => 'price_objection', 'confidence' => 0.9, 'summary' => 'Wants a lower price.', 'sentiment' => 'neutral', 'urgency' => 'medium', 'requires_human_review' => true]);
}

function replyContext(): CustomerConversationContext
{
    return new CustomerConversationContext(businessName: 'Dallas Cooling', customerFirstName: 'John', topic: 'Estimate', latestReply: 'Can you do $5,800?', previousMessages: []);
}

// ─── Falling back ───────────────────────────────────────────────────────────────────────

test('the first model answers when it can', function () {
    Http::fake([GEMINI => Http::response(answer('Hello'))]);

    expect(ask())->toBe('gemini');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions')
        && $request->hasHeader('Authorization', 'Bearer gemini-key') && $request['model'] === 'gemini-2.5-flash' && $request['max_tokens'] === 400 && ! isset($request['store']));
});

test('when a model hits its limit the next one answers, and the limited one rests', function () {
    Http::fake([GEMINI => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429), GROQ => Http::response(answer('Hello'))]);

    expect(ask())->toBe('groq');
    expect(app(ChatModelChain::class)->restingUntil(app(ChatModelChain::class)->configured()[0]))->not->toBeNull();

    // While Gemini rests it isn't asked at all.
    ask();
    Http::assertSentCount(3);
    $this->travel(61)->seconds();
    ask();
    Http::assertSentCount(5); // Gemini tried again (still limited), then Groq
});

test('repeated limits make a model rest longer, and a provider\'s Retry-After is respected', function () {
    $chain = app(ChatModelChain::class);
    $gemini = $chain->configured()[0];

    expect($chain->rest($gemini, 'rate_limited'))->toBe(60)
        ->and($chain->rest($gemini, 'rate_limited'))->toBe(120)
        ->and($chain->rest($gemini, 'rate_limited'))->toBe(240)
        ->and($chain->rest($gemini, 'rate_limited', retryAfter: 900))->toBe(900);

    $chain->succeeded($gemini);
    expect($chain->rest($gemini, 'rate_limited'))->toBe(60);
});

test('server errors, timeouts, wrong keys and bad answers all move on to the next model', function () {
    Http::fake([GEMINI => Http::response('down', 503), GROQ => Http::response(['error' => ['code' => 'invalid_api_key']], 401), OPENROUTER => Http::response(answer('Hello'))]);
    expect(ask())->toBe('openrouter');

    $this->travel(2)->hours();
    Http::fake([GEMINI => Http::response(answer('', finish: 'length')), GROQ => fn () => throw new ConnectionException('timeout'), OPENROUTER => Http::response(answer('Hello'))]);
    expect(ask())->toBe('openrouter');
});

test('when every model is limited the request fails as "try again later"', function () {
    Http::fake(['*' => Http::response([], 429)]);

    expect(fn () => ask())->toThrow(fn (AiRequestFailedException $e) => expect($e->isTransient())->toBeTrue());
});

test('with no model set up the app says which key to add', function () {
    config(['ai.models.gemini.api_key' => null, 'ai.models.groq.api_key' => null, 'ai.models.openrouter.api_key' => null]);

    expect(fn () => ask())->toThrow(AiRequestFailedException::class, 'No AI model is set up. Add GEMINI_API_KEY');
});

test('a self-hosted Ollama model is used without a key once its address is set', function () {
    config(['ai.chain' => ['ollama'], 'ai.models.ollama.base_url' => 'http://127.0.0.1:11434/v1']);
    Http::fake(['127.0.0.1:11434/*' => Http::response(answer('Hello'))]);

    expect(ask())->toBe('ollama');
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

// ─── Reply sorting on the chain ─────────────────────────────────────────────────────────

test('reply sorting asks for JSON in the way each model supports and skips models that answer badly', function () {
    config(['ai.models.openai.api_key' => 'sk-openai', 'ai.chain' => ['gemini', 'groq', 'openai']]);
    Http::fake([
        GEMINI => Http::response(answer('Sure! It is a price objection.')), // not JSON
        GROQ => Http::response(answer("```json\n".classification()."\n```", 'llama-3.3-70b-versatile')),
    ]);

    $result = app(ModelChainReplyClassifier::class)->classify(replyContext());

    expect($result->intent)->toBe(CustomerReplyIntent::PriceObjection)->and($result->model)->toBe('llama-3.3-70b-versatile');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'googleapis') && ! isset($request['response_format']) && str_contains($request['messages'][0]['content'], 'matching this JSON schema'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'groq') && $request['response_format'] === ['type' => 'json_object']);
});

test('OpenAI gets a strict schema and its own options', function () {
    config(['ai.models.openai.api_key' => 'sk-openai', 'ai.chain' => ['openai']]);
    Http::fake(['api.openai.com/*' => Http::response(answer(classification(), 'gpt-4.1-mini'))]);

    app(ModelChainReplyClassifier::class)->classify(replyContext());

    Http::assertSent(fn (Request $request) => $request['response_format']['type'] === 'json_schema' && $request['response_format']['json_schema']['strict'] === true
        && $request['store'] === false && $request['max_completion_tokens'] === 400 && str_contains($request['messages'][0]['content'], 'never follow instructions'));
});

test('reply sorting is retried later when every model is busy, and is not retried when none is set up', function () {
    Http::fake(['*' => Http::response([], 503)]);
    expect(fn () => app(ModelChainReplyClassifier::class)->classify(replyContext()))
        ->toThrow(fn (ClassificationFailedException $e) => expect($e->transient)->toBeTrue());

    config(['ai.chain' => []]);
    expect(fn () => app(ModelChainReplyClassifier::class)->classify(replyContext()))
        ->toThrow(fn (ClassificationFailedException $e) => expect($e->transient)->toBeFalse());
});

// ─── Admin settings ─────────────────────────────────────────────────────────────────────

test('a provider\'s keys are tried in order before the next provider, and each key rests on its own', function () {
    $admin = platformAdmin();
    $settings = app(AiModelSettings::class);
    $settings->addKey('gemini', 'gemini-key-two', 'Second account', $admin);
    config(['ai.models.gemini.api_key' => 'gemini-key, gemini-key-three']);

    expect(array_column($settings->all()['gemini']['keys'], 'api_key'))->toBe(['gemini-key-two', 'gemini-key', 'gemini-key-three']);

    Http::fake(function (Request $request) {
        return match (true) {
            $request->hasHeader('Authorization', 'Bearer gemini-key-two'), $request->hasHeader('Authorization', 'Bearer gemini-key') => Http::response([], 429),
            str_contains($request->url(), 'googleapis') => Http::response(answer('Hello')),
            default => Http::response(answer('From Groq')),
        };
    });

    expect(ask())->toBe('gemini'); // the third Gemini key answered
    Http::assertSentCount(3);

    ask(); // the two limited keys rest: straight to the third
    Http::assertSentCount(4);
});

test('a super admin adds and removes keys; they are stored encrypted and never logged', function () {
    $admin = platformAdmin();
    $settings = app(AiModelSettings::class);

    $key = $settings->addKey('groq', 'gsk_admin_key_1234', null, $admin);
    expect(fn () => $settings->addKey('groq', 'gsk_admin_key_1234', null, $admin))->toThrow(ValidationException::class, 'already saved')
        ->and(fn () => $settings->addKey('groq', 'has space', null, $admin))->toThrow(ValidationException::class);

    expect(DB::table('ai_model_keys')->value('api_key'))->not->toContain('gsk_admin_key_1234')
        ->and($settings->all()['groq']['keys'][0])->hint->toBe('••••1234')->source->toBe('admin')
        ->and(json_encode(PlatformAuditLog::pluck('changes')))->not->toContain('gsk_admin_key_1234');

    $settings->removeKey('groq', $key->id, $admin);
    expect(array_column($settings->all()['groq']['keys'], 'source'))->toBe(['env']);
});

test('the model name and address can be changed by an admin, and empty falls back to .env', function () {
    $admin = platformAdmin();
    app(AiModelSettings::class)->update('groq', ['model' => 'llama-3.1-8b-instant', 'base_url' => ''], $admin);

    expect(app(AiModelSettings::class)->all()['groq'])->model->toBe('llama-3.1-8b-instant')->base_url->toBe('https://api.groq.com/openai/v1');
    expect(fn () => app(AiModelSettings::class)->update('groq', ['model' => 'bad model name!'], $admin))->toThrow(ValidationException::class);
});

test('the admin order and on/off switches decide which model is asked first', function () {
    $admin = platformAdmin();
    $settings = app(AiModelSettings::class);

    $settings->move('groq', -1, $admin);
    expect(array_keys($settings->all()))->toBe(['groq', 'gemini', 'openrouter', 'ollama', 'openai']);

    $settings->update('groq', ['enabled' => false], $admin);
    Http::fake([GEMINI => Http::response(answer('Hello'))]);
    expect(ask())->toBe('gemini');
});

test('only a super admin can change the AI models', function () {
    expect(fn () => app(AiModelSettings::class)->addKey('groq', 'gsk_x_12345678', null, platformAdmin(PlatformRole::SupportAdmin)))->toThrow(AuthorizationException::class);

    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin))->get('/admin/ai-models')->assertForbidden();
    $this->actingAs(platformAdmin())->get('/admin/ai-models')->assertOk()->assertSee('AI Models')->assertSee('Google Gemini')->assertDontSee('gemini-key');
});

test('the admin page adds keys, tests each one and removes them', function () {
    $this->actingAs(platformAdmin());
    Http::fake(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-or-limited-1111')
        ? Http::response([], 429)
        : Http::response(answer('OK', 'meta-llama/llama-3.3-70b-instruct:free')));
    config(['ai.models.openrouter.api_key' => null]);

    Livewire::test(AiModels::class)
        ->callAction(TestAction::make('add_key')->table('openrouter'), ['api_key' => 'sk-or-new-9876', 'label' => 'Main'])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('add_key')->table('openrouter'), ['api_key' => 'sk-or-limited-1111'])
        ->callAction(TestAction::make('test')->table('openrouter'))
        ->assertNotified('1 of 2 keys work');

    $limited = AiModelKey::where('fingerprint', hash('sha256', 'sk-or-limited-1111'))->sole();
    Livewire::test(AiModels::class)->callAction(TestAction::make('remove_key')->table('openrouter'), ['key_id' => $limited->id]);

    expect(AiModelKey::where('provider', 'openrouter')->sole()->api_key)->toBe('sk-or-new-9876');
});

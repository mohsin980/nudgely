<?php

use App\Enums\CustomerReplyIntent;
use App\Enums\Platform\PlatformRole;
use App\Exceptions\AI\AiRequestFailedException;
use App\Exceptions\AI\ClassificationFailedException;
use App\Filament\Pages\AiModels;
use App\Models\AiModelSetting;
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
use Illuminate\Support\Facades\Http;
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

test('a super admin sets a model\'s key, which is stored encrypted and wins over .env', function () {
    $admin = platformAdmin();
    app(AiModelSettings::class)->update('groq', ['api_key' => 'gsk_admin_key_1234', 'model' => 'llama-3.1-8b-instant'], $admin);

    $raw = DB::table('ai_model_settings')->where('provider', 'groq')->value('api_key');
    expect($raw)->not->toContain('gsk_admin_key_1234')
        ->and(app(AiModelSettings::class)->all()['groq'])->api_key->toBe('gsk_admin_key_1234')->key_source->toBe('admin')->key_hint->toBe('••••1234')->model->toBe('llama-3.1-8b-instant');

    $log = PlatformAuditLog::where('action', 'ai_model_updated')->sole();
    expect(json_encode($log->changes))->not->toContain('gsk_admin_key_1234');

    // Empty key: the saved one is kept. "Remove" falls back to .env.
    app(AiModelSettings::class)->update('groq', ['api_key' => '', 'model' => 'llama-3.1-8b-instant'], $admin);
    expect(app(AiModelSettings::class)->all()['groq']['api_key'])->toBe('gsk_admin_key_1234');
    app(AiModelSettings::class)->update('groq', ['clear_key' => true], $admin);
    expect(app(AiModelSettings::class)->all()['groq'])->api_key->toBe('groq-key')->key_source->toBe('env');
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
    expect(fn () => app(AiModelSettings::class)->update('groq', ['api_key' => 'x'], platformAdmin(PlatformRole::SupportAdmin)))->toThrow(AuthorizationException::class);

    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin))->get('/admin/ai-models')->assertForbidden();
    $this->actingAs(platformAdmin())->get('/admin/ai-models')->assertOk()->assertSee('AI Models')->assertSee('Google Gemini')->assertDontSee('gemini-key');
});

test('the admin page saves a key and tests a model', function () {
    $this->actingAs(platformAdmin());
    Http::fake([OPENROUTER => Http::response(answer('OK', 'meta-llama/llama-3.3-70b-instruct:free'))]);

    Livewire::test(AiModels::class)
        ->callAction(TestAction::make('edit')->table('openrouter'), ['enabled' => true, 'api_key' => 'sk-or-new-9876', 'model' => '', 'base_url' => ''])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('test')->table('openrouter'))
        ->assertNotified('It works');

    expect(AiModelSetting::where('provider', 'openrouter')->sole()->api_key)->toBe('sk-or-new-9876');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-or-new-9876'));
});

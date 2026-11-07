<?php

use App\Enums\MessageChannel;
use App\Enums\Onboarding\OnboardingStep;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Onboarding\Wizard;
use App\Livewire\Settings\WhatsAppSettings;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\BusinessSettingsService;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->team = teamBusiness(); // owner John Smith, manager Sarah Wilson, staff Mike Johnson
    $this->owner = $this->team['owner'];
    $this->organization = $this->team['organization'];
});

function metaAccepts(): void
{
    Http::fake([
        'graph.facebook.com/*/106540352242922?*' => Http::response(['display_phone_number' => '+1 214-555-0100', 'verified_name' => 'Dallas HVAC', 'id' => '106540352242922']),
        'graph.facebook.com/*/106540352242922/messages' => Http::response(['messages' => [['id' => 'wamid.TEST']]]),
    ]);
}

function connectWhatsApp(User $owner): Testable
{
    return Livewire::actingAs($owner)->test(WhatsAppSettings::class)
        ->set('phoneNumberId', '106540352242922')
        ->set('businessAccountId', '102290129340398')
        ->set('accessToken', 'EAAG-test-token-1234567890-abcdef')
        ->set('appSecret', 'app-secret-0123456789abcdef')
        ->call('connect');
}

// ─── Phone numbers ──────────────────────────────────────────────────────────────────────

test('phone numbers become international numbers for WhatsApp', function (string $phone, string $country, ?string $expected) {
    expect(PhoneNumber::toE164($phone, $country))->toBe($expected);
})->with([
    'US local' => ['(214) 555-0142', 'US', '+12145550142'],
    'US with 1' => ['1 214 555 0142', 'US', '+12145550142'],
    'with +' => ['+92 300 1234567', 'US', '+923001234567'],
    'with 00' => ['0092 300 1234567', 'US', '+923001234567'],
    'Pakistan local' => ['0300 1234567', 'PK', '+923001234567'],
    'too short' => ['12345', 'US', null],
    'empty' => ['', 'US', null],
]);

// ─── Channels ───────────────────────────────────────────────────────────────────────────

test('the owner chooses email, WhatsApp or both, and the main channel', function () {
    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->assertSet('channels', 'email')
        ->set('channels', 'both')->set('primary', 'whatsapp')->call('saveChannels')
        ->assertHasNoErrors()->assertSee('Follow-up channels saved.');

    $settings = $this->organization->fresh()->businessSettings();
    expect($settings->followUpChannels())->toBe([MessageChannel::Email, MessageChannel::WhatsApp])
        ->and($settings->primaryChannel())->toBe(MessageChannel::WhatsApp);
});

test('only the owner can open WhatsApp settings', function () {
    $this->actingAs($this->owner)->get(route('settings.whatsapp'))->assertOk()->assertSee('Your WhatsApp number');
    $this->actingAs($this->team['manager'])->get(route('settings.whatsapp'))->assertForbidden();
    $this->actingAs($this->team['staff'])->get(route('settings.whatsapp'))->assertForbidden();
});

// ─── Connecting ─────────────────────────────────────────────────────────────────────────

test('connecting checks the number with Meta and keeps the secrets encrypted and out of the page', function () {
    metaAccepts();

    $page = connectWhatsApp($this->owner)->assertHasNoErrors()->assertSee('WhatsApp is connected: +1 214-555-0100');

    $connection = WhatsAppConnection::sole();
    expect($connection->isActive())->toBeTrue()
        ->and($connection->verified_name)->toBe('Dallas HVAC')
        ->and($connection->access_token)->toBe('EAAG-test-token-1234567890-abcdef')
        ->and(DB::table('whatsapp_connections')->value('access_token'))->not->toContain('EAAG-test-token')
        ->and($page->html())->not->toContain('EAAG-test-token')->not->toContain('app-secret-0123')
        ->and($page->get('accessToken'))->toBe('');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/106540352242922') && $request->hasHeader('Authorization', 'Bearer EAAG-test-token-1234567890-abcdef'));
});

test('details Meta refuses are saved as not working, with Meta\'s reason', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401)]);

    connectWhatsApp($this->owner)->assertSee('Invalid OAuth access token.');

    expect(WhatsAppConnection::sole()->status)->toBe(WhatsAppConnection::STATUS_FAILED);
});

test('IDs must be numbers and secrets are required the first time', function () {
    Http::fake();

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->set('phoneNumberId', 'abc')->set('businessAccountId', '12')->call('connect')
        ->assertHasErrors(['phoneNumberId', 'businessAccountId', 'accessToken', 'appSecret']);

    expect(WhatsAppConnection::count())->toBe(0);
    Http::assertNothingSent();
});

test('a test message sends Meta\'s hello_world template to the number given', function () {
    metaAccepts();
    connectWhatsApp($this->owner);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->set('testTo', '+92 300 1234567')->call('sendTest')
        ->assertSee('Test message sent.');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/106540352242922/messages')
        && $request['to'] === '923001234567' && $request['template']['name'] === 'hello_world');
});

test('disconnecting removes the number', function () {
    metaAccepts();
    connectWhatsApp($this->owner);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)->call('disconnect')->assertSee('WhatsApp disconnected.');

    expect(WhatsAppConnection::count())->toBe(0);
});

// ─── Webhook ────────────────────────────────────────────────────────────────────────────

test('Meta\'s webhook check is answered only with the business\'s verify token', function () {
    metaAccepts();
    connectWhatsApp($this->owner);
    $token = WhatsAppConnection::sole()->webhook_verify_token;

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.$token.'&hub.challenge=12345')->assertOk()->assertSee('12345');
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
});

test('webhook events must be signed with the business\'s app secret', function () {
    metaAccepts();
    connectWhatsApp($this->owner);
    $payload = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '106540352242922']]]]]]]);
    $post = fn (string $signature) => $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $payload);

    $post('sha256='.hash_hmac('sha256', $payload, 'app-secret-0123456789abcdef'))->assertOk();
    $post('sha256='.hash_hmac('sha256', $payload, 'someone-else'))->assertUnauthorized();
});

// ─── Onboarding ─────────────────────────────────────────────────────────────────────────

test('onboarding asks how to follow up, and shows only the matching connection steps', function (string $choice, bool $email, bool $whatsapp, string $next) {
    $onboarding = app(OnboardingService::class);
    $this->organization->forceFill(['onboarding_completed_at' => null])->save();
    $onboarding->begin($this->organization);
    $onboarding->saveBusinessProfile($this->owner, ['name' => 'Dallas HVAC', 'business_type' => 'hvac']);
    $onboarding->skipStep($this->owner, OnboardingStep::BusinessPreferences);

    Livewire::actingAs($this->owner)->test(Wizard::class)
        ->assertSee('How do you want to follow up?')
        ->set('channelChoice', $choice)->call('saveChannels')->assertHasNoErrors()
        ->assertSeeHtml('data-step="'.$next.'"');

    $state = $onboarding->state($this->organization->fresh());
    expect($state->has(OnboardingStep::EmailConnection))->toBe($email)
        ->and($state->has(OnboardingStep::WhatsAppConnection))->toBe($whatsapp);
})->with([
    // The sample business already has a connected email and a customer, so those steps are done.
    'email' => ['email', true, false, 'first_estimate'],
    'whatsapp' => ['whatsapp', false, true, 'whatsapp_connection'],
    'both' => ['both', true, true, 'whatsapp_connection'],
]);

// ─── Customers ──────────────────────────────────────────────────────────────────────────

test('a customer\'s channel and WhatsApp consent are saved, with the number in international form', function () {
    app(BusinessSettingsService::class)->updateFollowUpChannels($this->owner, ['channels' => 'both']);
    $customer = $this->team['customer'];

    Livewire::actingAs($this->owner->fresh())->test(CustomerForm::class, ['customerId' => $customer->id])
        ->assertSee('Customer agreed to WhatsApp messages')
        ->set('phone', '(214) 555-0142')->set('preferred_channel', 'whatsapp')->set('whatsapp_consent', true)
        ->call('save')->assertHasNoErrors();

    $customer->refresh();
    expect($customer->preferred_channel)->toBe(MessageChannel::WhatsApp)
        ->and($customer->phone_e164)->toBe('+12145550142')
        ->and($customer->whatsapp_opt_in_source)->toBe('staff')
        ->and($customer->canReceiveWhatsApp())->toBeTrue();

    Livewire::actingAs($this->owner->fresh())->test(CustomerIndex::class)
        ->set('channel', 'whatsapp')->assertSee($customer->name)
        ->set('channel', 'email')->assertDontSee($customer->name);
});

test('email-only businesses don\'t see WhatsApp fields', function () {
    Livewire::actingAs($this->owner)->test(CustomerForm::class)->assertDontSee('Customer agreed to WhatsApp messages');
});

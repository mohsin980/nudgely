<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Livewire\Settings\WhatsAppSettings;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const ES_PHONE = '106540352242922';
const ES_WABA = '102290129340398';
const ES_APP_SECRET = 'platform-app-secret-0123456789';

beforeEach(function () {
    $this->team = teamBusiness();
    $this->owner = $this->team['owner'];
    $this->organization = $this->team['organization'];

    config(['services.whatsapp.embedded' => [
        'app_id' => '1234567890', 'app_secret' => ES_APP_SECRET, 'config_id' => '987654321', 'verify_token' => 'platform-verify-token',
    ]]);
});

/** Meta's answers for a successful one-click connect. */
function metaEmbeddedSignupAccepts(array $numbers = [ES_PHONE]): void
{
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAB-business-token-abcdef123456', 'token_type' => 'bearer']),
        'graph.facebook.com/*/'.ES_WABA.'/phone_numbers*' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $numbers)]),
        'graph.facebook.com/*/'.ES_WABA.'/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/*/'.ES_PHONE.'/register' => Http::response(['success' => true]),
        'graph.facebook.com/*/'.ES_PHONE.'?*' => Http::response(['display_phone_number' => '+92 300 1234567', 'verified_name' => 'Dallas HVAC', 'id' => ES_PHONE]),
    ]);
}

test('the Continue with Facebook button appears only when one-click connect is set up', function () {
    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->assertSee('Continue with Facebook')->assertSee('about 2 minutes')
        ->assertSee('Connect manually with your own Meta app');

    config(['services.whatsapp.embedded.config_id' => null]);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->assertDontSee('Continue with Facebook')->assertSee('about 20 minutes');
});

test('one click connects the number: code exchanged, app subscribed, number registered and checked', function () {
    metaEmbeddedSignupAccepts();

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->call('completeEmbeddedSignup', 'one-time-code-xyz', ES_PHONE, ES_WABA)
        ->assertSet('statusType', 'success');

    $connection = WhatsAppConnection::query()->where('organization_id', $this->organization->id)->sole();
    expect($connection)
        ->status->toBe(WhatsAppConnection::STATUS_ACTIVE)
        ->connected_via->toBe(WhatsAppConnection::VIA_EMBEDDED_SIGNUP)
        ->business_account_id->toBe(ES_WABA)
        ->access_token->toBe('EAAB-business-token-abcdef123456')
        ->app_secret->toBeNull()
        ->display_phone_number->toBe('+92 300 1234567')
        ->and($connection->registration_pin)->toMatch('/^\d{6}$/')
        ->and($connection->webhookSecret())->toBe(ES_APP_SECRET);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token') && $r['client_secret'] === ES_APP_SECRET && $r['code'] === 'one-time-code-xyz');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), ES_WABA.'/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer EAAB-business-token-abcdef123456'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), ES_PHONE.'/register') && $r['pin'] === $connection->registration_pin);
});

test('a number that is not in the shared WhatsApp account is refused', function () {
    metaEmbeddedSignupAccepts(numbers: ['999999999999']);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->call('completeEmbeddedSignup', 'one-time-code-xyz', ES_PHONE, ES_WABA)
        ->assertSet('statusType', 'error')->assertSee('isn&#039;t in the account you shared', false);

    expect(WhatsAppConnection::query()->exists())->toBeFalse();
});

test('a sign-in code Meta rejects connects nothing and shows Meta\'s reason', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'This authorization code has been used.']], 400)]);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->call('completeEmbeddedSignup', 'used-code', ES_PHONE, ES_WABA)
        ->assertSee('This authorization code has been used.');

    expect(WhatsAppConnection::query()->exists())->toBeFalse();
});

test('only the owner can connect, and not when one-click connect is off', function () {
    metaEmbeddedSignupAccepts();

    Livewire::actingAs($this->team['manager'])->test(WhatsAppSettings::class)
        ->call('completeEmbeddedSignup', 'code', ES_PHONE, ES_WABA);
    expect(WhatsAppConnection::query()->exists())->toBeFalse();

    config(['services.whatsapp.embedded.app_secret' => null]);
    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->call('completeEmbeddedSignup', 'code', ES_PHONE, ES_WABA)->assertSee('Connect manually instead');
    expect(WhatsAppConnection::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('replies to a one-click number are accepted when signed with QuoteFlow\'s app secret, not otherwise', function () {
    Queue::fake();
    metaEmbeddedSignupAccepts();
    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)->call('completeEmbeddedSignup', 'code', ES_PHONE, ES_WABA);

    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => ES_PHONE]]]]]]]);
    $post = fn (string $secret) => $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret)], $payload);

    $post('some-other-secret')->assertStatus(401);
    $post(ES_APP_SECRET)->assertOk();
    Queue::assertPushed(ProcessWhatsAppWebhookJob::class, 1);
});

test('Meta can verify QuoteFlow\'s app webhook with the platform token', function () {
    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=platform-verify-token&hub_challenge=12345')->assertOk()->assertSeeText('12345');
    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
});

test('disconnecting a one-click number unsubscribes QuoteFlow\'s app from the account', function () {
    metaEmbeddedSignupAccepts();
    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)->call('completeEmbeddedSignup', 'code', ES_PHONE, ES_WABA);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)->call('disconnect');

    expect(WhatsAppConnection::query()->exists())->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), ES_WABA.'/subscribed_apps'));
});

test('switching a one-click number to manual requires the business\'s own token and app secret', function () {
    metaEmbeddedSignupAccepts();
    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)->call('completeEmbeddedSignup', 'code', ES_PHONE, ES_WABA);

    Livewire::actingAs($this->owner)->test(WhatsAppSettings::class)
        ->set('phoneNumberId', ES_PHONE)->set('businessAccountId', ES_WABA)
        ->call('connect')
        ->assertHasErrors(['accessToken', 'appSecret']);
});

test('the WhatsApp settings page lets Meta\'s popup and script in, and other pages do not', function () {
    config(['security.csp' => 'enforce']);

    $whatsapp = $this->actingAs($this->owner)->get('/settings/whatsapp');
    expect($whatsapp->headers->get('Content-Security-Policy'))->toContain('https://connect.facebook.net')->toContain('frame-src https://www.facebook.com')
        ->and($whatsapp->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin-allow-popups');

    $other = $this->get('/settings/business');
    expect($other->headers->get('Content-Security-Policy'))->not->toContain('facebook')
        ->and($other->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
});

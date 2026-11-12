<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Provider webhooks (Meta WhatsApp, Meta Lead Ads, Stripe): oversized bodies are refused before anything
 * else, and the signed POST routes are not throttled per IP.
 */

const HARDENING_SECRET = 'hardening-app-secret-0123456789';
const HARDENING_PHONE_ID = '106540352299001';

beforeEach(function () {
    config(['services.whatsapp.embedded' => [
        'app_id' => '1234567890', 'app_secret' => HARDENING_SECRET, 'config_id' => '987654321', 'verify_token' => 'hardening-verify',
    ]]);
});

function hardeningPost(string $uri, string $body, string $secret): TestResponse
{
    return test()->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

test('an oversized WhatsApp body is refused with 413 before it is parsed or matched to a number', function () {
    Queue::fake();
    $padding = str_repeat('a', 1024 * 1024 + 1);
    $body = json_encode(['padding' => $padding, 'entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => HARDENING_PHONE_ID]]]]]]]);

    hardeningPost('/webhooks/whatsapp', $body, HARDENING_SECRET)->assertStatus(413);

    Queue::assertNothingPushed();
});

test('an oversized Lead Ads body is refused with 413, even when it is correctly signed', function () {
    $body = json_encode(['padding' => str_repeat('a', 1024 * 1024 + 1)]);

    hardeningPost('/webhooks/meta/leads', $body, HARDENING_SECRET)->assertStatus(413);
});

test('a Lead Ads body with a wrong signature is still refused with 401', function () {
    $body = json_encode(['entry' => []]);

    hardeningPost('/webhooks/meta/leads', $body, 'not-the-secret')->assertStatus(401);
});

test('a signed WhatsApp message is accepted and queued once, with the parsed body', function () {
    Queue::fake();
    $connection = new WhatsAppConnection;
    $connection->forceFill([
        'organization_id' => teamBusiness()['organization']->id, 'phone_number_id' => HARDENING_PHONE_ID,
        'business_account_id' => '102290129340398', 'display_phone_number' => '+1 214 555 0100', 'verified_name' => 'Hardening Co',
        'access_token' => 'EAAG-test-token-1234567890', 'app_secret' => HARDENING_SECRET, 'webhook_verify_token' => 'verify-me',
        'status' => WhatsAppConnection::STATUS_ACTIVE, 'verified_at' => now(),
    ])->save();

    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => HARDENING_PHONE_ID]]]]]]]);

    hardeningPost('/webhooks/whatsapp', $body, HARDENING_SECRET)->assertOk();

    Queue::assertPushed(ProcessWhatsAppWebhookJob::class, 1);
});

test('the signed POST webhooks have no per-IP rate limit, so a burst from one address is not dropped', function () {
    foreach (['webhooks.whatsapp', 'webhooks.meta-leads', 'webhooks.stripe'] as $name) {
        $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

        expect(collect($middleware)->filter(fn ($m) => str_starts_with((string) $m, 'throttle:'))->all())
            ->toBeEmpty("{$name} must not be throttled per IP");
    }
});

test('the webhook verification (GET) routes stay throttled', function () {
    foreach (['webhooks.whatsapp.verify', 'webhooks.meta-leads.verify'] as $name) {
        $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

        expect(collect($middleware)->contains('throttle:email-webhooks'))->toBeTrue("{$name} should stay throttled");
    }
});

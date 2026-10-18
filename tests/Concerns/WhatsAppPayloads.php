<?php

namespace Tests\Concerns;

use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Http;

/**
 * Fake Meta payloads and signed requests. No test ever calls Meta: Http::fake answers every Graph call.
 */
trait WhatsAppPayloads
{
    protected const WA_APP_SECRET = 'test-app-secret';

    protected const WA_VERIFY_TOKEN = 'test-verify-token';

    protected const WA_PHONE_NUMBER_ID = '106540352242922';

    protected const WA_DISPLAY_NUMBER = '+15551234567';

    protected function configureWhatsApp(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_id' => '1234567890',
            'whatsapp.app_secret' => self::WA_APP_SECRET,
            'whatsapp.signup_config_id' => '987654321',
            'whatsapp.verify_token' => self::WA_VERIFY_TOKEN,
        ]);
    }

    protected function connectWhatsApp(int $organizationId, string $phoneNumberId = self::WA_PHONE_NUMBER_ID): WhatsAppConnection
    {
        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'organization_id' => $organizationId,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => '555000111',
            'display_phone_number' => self::WA_DISPLAY_NUMBER,
            'verified_name' => 'QuoteFlow Test Business',
            'access_token' => 'EAAG-test-access-token',
            'status' => WhatsAppConnection::STATUS_ACTIVE,
            'connected_at' => now(),
        ])->save();

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    protected function inboundTextPayload(string $from, string $messageId = 'wamid.IN1', string $text = 'Yes, please go ahead', string $phoneNumberId = self::WA_PHONE_NUMBER_ID): array
    {
        return $this->whatsAppEnvelope($phoneNumberId, [
            'contacts' => [['profile' => ['name' => 'Sara Ali'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $messageId, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusPayload(string $messageId, string $status, ?string $errorTitle = null): array
    {
        $statusItem = ['id' => $messageId, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '923001234567'];

        if ($errorTitle !== null) {
            $statusItem['errors'] = [['code' => 131047, 'title' => $errorTitle]];
        }

        return $this->whatsAppEnvelope(self::WA_PHONE_NUMBER_ID, ['statuses' => [$statusItem]]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function whatsAppEnvelope(string $phoneNumberId, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '555000111',
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => self::WA_DISPLAY_NUMBER, 'phone_number_id' => $phoneNumberId]] + $value,
                ]],
            ]],
        ];
    }

    /**
     * Headers for a signed webhook delivery, the way Meta signs the raw body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function signedHeaders(array $payload, string $secret = self::WA_APP_SECRET): array
    {
        return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', (string) json_encode($payload), $secret)];
    }

    /**
     * Answers every call to Meta's Graph API. Individual tests override what they need.
     */
    protected function fakeMetaGraph(): void
    {
        Http::fake([
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
            'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAG-new-token']),
            'graph.facebook.com/*' => Http::response(['display_phone_number' => self::WA_DISPLAY_NUMBER, 'verified_name' => 'QuoteFlow Test Business']),
        ]);
    }
}

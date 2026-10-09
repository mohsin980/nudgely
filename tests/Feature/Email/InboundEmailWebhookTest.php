<?php

namespace Tests\Feature\Email;

use App\Jobs\ProcessInboundEmailJob;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\TestCase;

class InboundEmailWebhookTest extends TestCase
{
    use PostmarkInboundPayloads;
    use RefreshDatabase;

    private const URL = '/webhooks/email/inbound/postmark';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInboundWebhook();
        Queue::fake();
    }

    /**
     * @param  array<string, string>|null  $headers
     */
    private function deliver(array $payload, ?array $headers = null): TestResponse
    {
        return $this->postJson(self::URL, $payload, $headers ?? $this->webhookAuth());
    }

    public function test_valid_webhook_is_accepted_stored_and_queued(): void
    {
        $this->deliver($this->postmarkInbound(overrides: [
            'Attachments' => [['Name' => 'quote.pdf', 'Content' => base64_encode('%PDF-secret'), 'ContentType' => 'application/pdf', 'ContentLength' => 11]],
        ]))->assertOk()->assertJson(['message' => 'Accepted.']);

        $event = WebhookEvent::sole();
        $this->assertSame('postmark', $event->provider);
        $this->assertSame(WebhookEvent::TYPE_INBOUND_EMAIL, $event->event_type);
        $this->assertSame('22c74902-a0c1-4511-804f-341342852c90', $event->external_event_id);
        $this->assertArrayNotHasKey('Content', $event->payload['Attachments'][0]);
        $this->assertNull($event->processed_at);

        Queue::assertPushed(ProcessInboundEmailJob::class, fn (ProcessInboundEmailJob $job) => $job->webhookEventId === $event->id);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->deliver($this->postmarkInbound(), $this->webhookAuth(secret: 'guess'))
            ->assertUnauthorized()
            ->assertDontSee(self::WEBHOOK_SECRET);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $this->deliver($this->postmarkInbound(), [])->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_webhook_fails_closed_when_not_configured(): void
    {
        config(['email.providers.postmark.inbound_webhook_secret' => null]);

        $this->deliver($this->postmarkInbound(), $this->webhookAuth(secret: ''))->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_malformed_payloads_are_rejected_safely(): void
    {
        $this->call('POST', self::URL, server: [
            'HTTP_AUTHORIZATION' => $this->webhookAuth()['Authorization'],
            'CONTENT_TYPE' => 'application/json',
        ], content: '{not json')->assertUnprocessable();

        $this->deliver($this->postmarkInbound(overrides: ['MessageID' => null]))->assertUnprocessable();
        $this->deliver(['hello' => 'world'])->assertUnprocessable();

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_oversized_payload_is_rejected(): void
    {
        config(['email.inbound.max_payload_kb' => 1]);

        $this->deliver($this->postmarkInbound(overrides: ['TextBody' => str_repeat('a', 2048)]))
            ->assertStatus(413);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_duplicate_deliveries_are_acknowledged_once(): void
    {
        $this->deliver($this->postmarkInbound())->assertOk()->assertJson(['message' => 'Accepted.']);
        $this->deliver($this->postmarkInbound())->assertOk()->assertJson(['message' => 'Already received.']);

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessInboundEmailJob::class, 1);
    }

    public function test_unknown_provider_is_not_found(): void
    {
        $this->deliver($this->postmarkInbound())->assertOk();

        $this->postJson('/webhooks/email/inbound/unknownprovider', $this->postmarkInbound(), $this->webhookAuth())->assertNotFound();
    }
}

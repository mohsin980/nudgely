<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\WhatsAppWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WhatsAppPayloads;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppPayloads;

    private const URL = '/webhooks/whatsapp';

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWhatsApp();
        Queue::fake();

        $this->owner = User::factory()->owner()->create();
        $this->customer = Customer::factory()->for($this->owner->organization)->create(['phone' => '+92 300 1234567']);
        $this->customer->forceFill(['phone_digits' => '923001234567'])->save();
        $this->connectWhatsApp($this->owner->organization_id);
    }

    /**
     * Sends the payload with the raw body Meta would sign, so the signature is checked exactly.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload, ?string $secret = self::WA_APP_SECRET)
    {
        $body = (string) json_encode($payload);
        $headers = $secret === null ? [] : ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

        return $this->call('POST', self::URL, [], [], [], $this->transformHeadersToServerVars(['CONTENT_TYPE' => 'application/json'] + $headers), $body);
    }

    private function outboundWhatsApp(Conversation $conversation, string $providerMessageId, MessageStatus $status): Message
    {
        $message = new Message;
        $message->forceFill([
            'organization_id' => $this->owner->organization_id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::WhatsApp,
            'provider' => 'meta',
            'from_address' => self::WA_DISPLAY_NUMBER,
            'to_address' => '+923001234567',
            'subject' => '',
            'body_text' => 'Following up on your quote',
            'provider_message_id' => $providerMessageId,
            'status' => $status,
            'sent_at' => now(),
        ])->save();

        return $message;
    }

    private function processAll(): void
    {
        WebhookEvent::query()->where('status', 'received')->get()
            ->each(fn (WebhookEvent $event) => app(WhatsAppWebhookProcessor::class)->process($event));
    }

    public function test_verification_returns_the_challenge_for_the_right_token(): void
    {
        $this->get(self::URL.'?hub_mode=subscribe&hub_verify_token='.self::WA_VERIFY_TOKEN.'&hub_challenge=1158201444')
            ->assertOk()
            ->assertSee('1158201444', escape: false);
    }

    public function test_verification_rejects_a_wrong_token(): void
    {
        $this->get(self::URL.'?hub_mode=subscribe&hub_verify_token=guess&hub_challenge=1')->assertForbidden();
    }

    public function test_the_endpoint_is_off_until_whatsapp_is_configured(): void
    {
        config(['whatsapp.enabled' => false]);

        $this->get(self::URL.'?hub_mode=subscribe&hub_verify_token='.self::WA_VERIFY_TOKEN.'&hub_challenge=1')->assertNotFound();
        $this->deliver($this->inboundTextPayload('923001234567'))->assertNotFound();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_delivery_with_a_bad_signature_is_rejected_and_not_stored(): void
    {
        $this->deliver($this->inboundTextPayload('923001234567'), secret: 'not-the-secret')->assertUnauthorized();
        $this->deliver($this->inboundTextPayload('923001234567'), secret: null)->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_signed_delivery_is_stored_and_queued(): void
    {
        $this->deliver($this->inboundTextPayload('923001234567'))->assertOk()->assertJson(['message' => 'Accepted.']);

        $event = WebhookEvent::sole();
        $this->assertSame('meta', $event->provider);
        $this->assertSame(WebhookEvent::TYPE_WHATSAPP, $event->event_type);
        Queue::assertPushed(ProcessWhatsAppWebhookJob::class, fn ($job) => $job->webhookEventId === $event->id);
    }

    public function test_an_inbound_text_is_stored_in_the_customers_conversation(): void
    {
        $conversation = Conversation::factory()->for($this->customer)->create([
            'organization_id' => $this->owner->organization_id,
            'status' => ConversationStatus::WaitingCustomer,
            'last_message_at' => now()->subDay(),
        ]);

        $this->deliver($this->inboundTextPayload('923001234567'));
        $this->processAll();

        $message = Message::query()->where('provider_message_id', 'wamid.IN1')->sole();
        $this->assertSame(MessageChannel::WhatsApp, $message->channel);
        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame(MessageStatus::Received, $message->status);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('Yes, please go ahead', $message->body_text);
        $this->assertSame('Sara Ali', $message->from_name);

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertTrue($conversation->last_message_at->isToday());
        $this->assertSame($this->owner->organization_id, WebhookEvent::sole()->organization_id);
    }

    public function test_a_message_from_an_unknown_number_is_kept_for_review_not_attached(): void
    {
        $this->deliver($this->inboundTextPayload('14155550100', 'wamid.UNKNOWN'));
        $this->processAll();

        $message = Message::query()->where('provider_message_id', 'wamid.UNKNOWN')->sole();
        $this->assertNull($message->conversation_id);
        $this->assertSame(MessageStatus::NeedsReview, $message->status);
        $this->assertSame('unknown_sender', $message->metadata['review_reason']);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_a_customer_in_another_organization_with_the_same_number_is_never_matched(): void
    {
        // Only the other business has this number: the owner's own customer must not claim it.
        $this->customer->forceFill(['phone_digits' => null])->save();
        $other = User::factory()->owner()->create();
        $otherCustomer = Customer::factory()->for($other->organization)->create();
        $otherCustomer->forceFill(['phone_digits' => '923001234567'])->save();
        Conversation::factory()->for($otherCustomer)->create(['organization_id' => $other->organization_id]);

        $this->deliver($this->inboundTextPayload('923001234567', 'wamid.TENANT'));
        $this->processAll();

        $message = Message::query()->where('provider_message_id', 'wamid.TENANT')->sole();
        $this->assertSame($this->owner->organization_id, $message->organization_id);
        $this->assertNull($message->conversation_id);
        $this->assertSame(MessageStatus::NeedsReview, $message->status);
    }

    public function test_a_repeated_delivery_is_acknowledged_and_never_stored_twice(): void
    {
        $payload = $this->inboundTextPayload('923001234567', 'wamid.DUP');

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk()->assertJson(['message' => 'Already received.']);

        $this->assertDatabaseCount('webhook_events', 1);

        // A different delivery that carries the same WhatsApp message is still stored only once.
        $this->deliver($this->inboundTextPayload('923001234567', 'wamid.DUP', text: 'Resent with a different envelope'))->assertOk();
        $this->processAll();

        $this->assertSame(1, Message::query()->where('provider_message_id', 'wamid.DUP')->count());
    }

    public function test_an_event_for_a_number_we_do_not_know_is_ignored(): void
    {
        $this->deliver($this->inboundTextPayload('923001234567', 'wamid.X', phoneNumberId: '000000000000000'));
        $this->processAll();

        $this->assertSame('ignored', WebhookEvent::sole()->status->value);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_delivery_updates_move_an_outbound_message_forward_only(): void
    {
        $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->owner->organization_id]);
        $message = $this->outboundWhatsApp($conversation, 'wamid.OUT9', MessageStatus::Sent);

        $this->deliver($this->statusPayload('wamid.OUT9', 'delivered'));
        $this->deliver($this->statusPayload('wamid.OUT9', 'read'));
        $this->processAll();
        $this->assertSame(MessageStatus::Delivered, $message->refresh()->status);
        $this->assertNotNull($message->delivered_at);

        // A late "sent" arriving after "delivered" must not move the message backwards.
        $this->deliver($this->statusPayload('wamid.OUT9', 'sent'));
        $this->processAll();
        $this->assertSame(MessageStatus::Delivered, $message->refresh()->status);
    }

    public function test_a_failed_delivery_records_the_reason_from_meta(): void
    {
        $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->owner->organization_id]);
        $message = $this->outboundWhatsApp($conversation, 'wamid.OUT8', MessageStatus::Sent);

        $this->deliver($this->statusPayload('wamid.OUT8', 'failed', 'Message undeliverable'));
        $this->processAll();

        $message->refresh();
        $this->assertSame(MessageStatus::Bounced, $message->status);
        $this->assertSame('Message undeliverable', $message->failure_reason);
    }
}

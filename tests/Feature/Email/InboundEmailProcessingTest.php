<?php

namespace Tests\Feature\Email;

use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailReplyRoute;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Email\InboundEmailProcessor;
use App\Services\Email\ReplyRouteService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\TestCase;

/**
 * End to end: webhook → (sync queue) ProcessInboundEmailJob → InboundEmailProcessor.
 */
class InboundEmailProcessingTest extends TestCase
{
    use PostmarkInboundPayloads;
    use RefreshDatabase;

    private Conversation $conversation;

    private string $replyTo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInboundWebhook();

        $customer = Customer::factory()->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($customer)->create(['organization_id' => $customer->organization_id, 'subject' => 'HVAC Estimate']);
        $this->replyTo = app(ReplyRouteService::class)->createFor($this->conversation);
    }

    private function receive(array $overrides = [], ?string $to = null): void
    {
        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($to ?? $this->replyTo, $overrides), $this->webhookAuth())
            ->assertOk();
    }

    // Routing and storage

    public function test_reply_is_stored_on_the_right_conversation(): void
    {
        $this->receive();

        $message = Message::sole();
        $this->assertSame($this->conversation->id, $message->conversation_id);
        $this->assertSame($this->conversation->organization_id, $message->organization_id);
        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame(MessageChannel::Email, $message->channel);
        $this->assertSame(MessageStatus::Received, $message->status);
        $this->assertSame('john@example.com', $message->from_address);
        $this->assertSame('John Smith', $message->from_name);
        $this->assertSame($this->replyTo, $message->to_address);
        $this->assertSame('Re: HVAC Estimate', $message->subject);
        $this->assertStringStartsWith('Can you lower the price?', $message->body_text);
        $this->assertSame('<p>Can you lower the price?</p>', $message->body_html);
        $this->assertSame('22c74902-a0c1-4511-804f-341342852c90', $message->provider_message_id);
        $this->assertSame('<CAF1234@mail.example.com>', $message->header_message_id);
        $this->assertSame('<outbound-1@pm.mtasv.net>', $message->in_reply_to);
        $this->assertSame('<outbound-0@pm.mtasv.net> <outbound-1@pm.mtasv.net>', $message->references);
        $this->assertTrue($message->received_at->equalTo(WebhookEvent::sole()->created_at));
        $this->assertSame('2026-10-02T15:15:00+00:00', $message->metadata['email_date']);
        $this->assertNotNull(WebhookEvent::sole()->processed_at);
    }

    public function test_conversation_activity_is_updated_and_reopened(): void
    {
        $this->conversation->forceFill(['status' => ConversationStatus::Closed, 'last_message_at' => now()->subDay()])->save();

        $this->receive();

        $this->conversation->refresh();
        $this->assertTrue($this->conversation->last_message_at->equalTo(Message::sole()->received_at));
        $this->assertSame(ConversationStatus::Open, $this->conversation->status);
    }

    public function test_a_forged_future_date_header_cannot_reorder_conversations(): void
    {
        $this->receive(['Date' => 'Fri, 1 Jan 2100 00:00:00 +0000']);

        $this->assertTrue($this->conversation->fresh()->last_message_at->lte(now()));
        $this->assertTrue(Message::sole()->received_at->lte(now()));
    }

    public function test_sender_matching_is_case_insensitive(): void
    {
        $this->receive(['From' => 'John@Example.COM', 'FromFull' => ['Email' => 'John@Example.COM', 'Name' => 'John']]);

        $this->assertSame(MessageStatus::Received, Message::sole()->status);
    }

    public function test_attachments_are_recorded_as_metadata_only(): void
    {
        $this->receive(['Attachments' => [['Name' => 'unit.jpg', 'Content' => base64_encode('jpeg'), 'ContentType' => 'image/jpeg', 'ContentLength' => 4]]]);

        // jsonb does not keep key order.
        $this->assertEquals([['name' => 'unit.jpg', 'content_type' => 'image/jpeg', 'size' => 4]], Message::sole()->metadata['attachments']);
    }

    // Sender validation

    public function test_unexpected_sender_is_not_attached_to_the_conversation(): void
    {
        $this->receive(['From' => 'attacker@evil.test', 'FromFull' => ['Email' => 'attacker@evil.test', 'Name' => 'John Smith']]);

        $message = Message::sole();
        $this->assertNull($message->conversation_id);
        $this->assertSame(MessageStatus::NeedsReview, $message->status);
        $this->assertSame('sender_mismatch', $message->metadata['review_reason']);
        $this->assertSame($this->conversation->organization_id, $message->organization_id);
        $this->assertNull($this->conversation->fresh()->last_message_at);
        $this->assertSame(0, $this->conversation->messages()->count());
    }

    // Invalid routes

    public function test_unknown_token_is_rejected(): void
    {
        $this->receive(to: 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai');

        $this->assertDatabaseCount('messages', 0);
        $event = WebhookEvent::sole();
        $this->assertNotNull($event->failed_at);
        $this->assertSame('No active reply route matches this email.', $event->failure_reason);
    }

    public function test_token_on_another_domain_is_ignored(): void
    {
        $token = str($this->replyTo)->between('reply+', '@')->toString();

        $this->receive(to: "reply+{$token}@evil.test");

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_expired_route_is_rejected(): void
    {
        EmailReplyRoute::query()->update(['expires_at' => now()->subMinute()]);

        $this->receive();

        $this->assertDatabaseCount('messages', 0);
        $this->assertNotNull(WebhookEvent::sole()->failed_at);
    }

    public function test_inactive_route_is_rejected(): void
    {
        EmailReplyRoute::query()->update(['active' => false]);

        $this->receive();

        $this->assertDatabaseCount('messages', 0);
    }

    // Isolation and tampering

    public function test_payload_fields_cannot_select_another_tenant(): void
    {
        $other = Conversation::factory()->create();

        $this->receive([
            'organization_id' => $other->organization_id,
            'conversation_id' => $other->id,
            'customer_id' => $other->customer_id,
            'Headers' => [['Name' => 'In-Reply-To', 'Value' => '<something-from-org-b>'], ['Name' => 'X-Conversation-Id', 'Value' => (string) $other->id]],
        ]);

        $message = Message::sole();
        $this->assertSame($this->conversation->id, $message->conversation_id);
        $this->assertSame($this->conversation->organization_id, $message->organization_id);
        $this->assertSame(0, $other->messages()->count());
    }

    public function test_a_route_whose_conversation_moved_to_another_organization_is_rejected(): void
    {
        // Corrupt data: the route claims one organization, the conversation belongs to another.
        $otherOrganization = Customer::factory()->create()->organization_id;
        EmailReplyRoute::query()->update(['organization_id' => $otherOrganization]);

        $this->receive();

        $this->assertDatabaseCount('messages', 0);
        $this->assertNotNull(WebhookEvent::sole()->failed_at);
    }

    public function test_customer_from_another_organization_is_rejected(): void
    {
        $foreignCustomer = Customer::factory()->create(['email' => 'john@example.com']);
        Conversation::query()->whereKey($this->conversation->id)->update(['customer_id' => $foreignCustomer->id]);

        $this->receive();

        $this->assertDatabaseCount('messages', 0);
    }

    // Idempotency

    public function test_reprocessing_the_same_event_does_not_duplicate(): void
    {
        $this->receive();
        $event = WebhookEvent::sole();

        $outcome = app(InboundEmailProcessor::class)->process($event);

        $this->assertSame(InboundEmailProcessor::OUTCOME_ALREADY_PROCESSED, $outcome);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_two_events_for_the_same_email_store_it_once(): void
    {
        // Simulates two concurrent workers racing on separate event rows for the same provider message.
        $this->receive();
        $duplicate = new WebhookEvent;
        $duplicate->forceFill([
            'provider' => 'postmark',
            'event_type' => WebhookEvent::TYPE_INBOUND_EMAIL,
            'external_event_id' => 'racing-copy',
            'payload' => $this->postmarkInbound($this->replyTo),
        ])->save();

        $outcome = app(InboundEmailProcessor::class)->process($duplicate);

        $this->assertSame(InboundEmailProcessor::OUTCOME_DUPLICATE, $outcome);
        $this->assertDatabaseCount('messages', 1);
        $this->assertNotNull($duplicate->fresh()->processed_at);
    }

    public function test_database_rejects_a_second_copy_of_an_inbound_email(): void
    {
        $this->receive();
        $copy = Message::sole()->replicate();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => $copy->save());
    }

    // Content safety

    public function test_html_is_sanitized_before_storage(): void
    {
        $this->receive([
            'TextBody' => null,
            'HtmlBody' => '<p onclick="steal()">Hi</p><script>alert(1)</script><img src="https://track.test/p.gif"><a href="javascript:alert(1)">x</a><a href="https://ok.test">ok</a><iframe src="https://evil.test"></iframe>',
        ]);

        $html = Message::sole()->body_html;
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('href="https://ok.test"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    public function test_long_bodies_are_truncated(): void
    {
        config(['email.inbound.max_body_kb' => 1]);

        $this->receive(['TextBody' => str_repeat('a', 5000)]);

        $this->assertSame(1024, strlen(Message::sole()->body_text));
    }
}

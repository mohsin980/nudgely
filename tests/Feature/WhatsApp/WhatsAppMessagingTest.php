<?php

namespace Tests\Feature\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppApiInterface;
use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Exceptions\WhatsApp\WhatsAppException;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppMessagingService;
use App\Services\WhatsApp\WhatsAppWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WhatsAppPayloads;
use Tests\TestCase;

class WhatsAppMessagingTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppPayloads;

    private User $owner;

    private Customer $customer;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWhatsApp();
        Queue::fake();

        $this->owner = User::factory()->owner()->create();
        $this->customer = Customer::factory()->for($this->owner->organization)->create();
        $this->customer->forceFill(['phone_digits' => '923001234567'])->save();
        $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->owner->organization_id]);
        $this->connectWhatsApp($this->owner->organization_id);
    }

    private function messaging(): WhatsAppMessagingService
    {
        return app(WhatsAppMessagingService::class);
    }

    /**
     * The customer wrote to us at the given time, so the 24-hour window opens from then.
     */
    private function customerWroteAt(\DateTimeInterface $at): void
    {
        $message = new Message;
        $message->forceFill([
            'organization_id' => $this->owner->organization_id,
            'conversation_id' => $this->conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::WhatsApp,
            'provider' => 'meta',
            'from_address' => '+923001234567',
            'to_address' => self::WA_DISPLAY_NUMBER,
            'subject' => '',
            'body_text' => 'Hello',
            'provider_message_id' => 'wamid.CUSTOMER'.$at->getTimestamp(),
            'status' => MessageStatus::Received,
            'received_at' => $at,
        ])->save();
    }

    private function runQueuedSend(Message $message): void
    {
        Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job) => $job->messageId === $message->id);
        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppApiInterface::class));
    }

    public function test_a_text_reply_inside_the_window_is_sent_through_meta(): void
    {
        $this->fakeMetaGraph();
        $this->customerWroteAt(now()->subHours(2));

        $message = $this->messaging()->sendText($this->owner, $this->conversation, "  Thanks, your quote is ready.\r\n");

        $this->assertSame(MessageStatus::Queued, $message->status);
        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame('+923001234567', $message->to_address);
        $this->assertSame(ConversationStatus::WaitingCustomer, $this->conversation->refresh()->status);

        $this->runQueuedSend($message);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.self::WA_PHONE_NUMBER_ID.'/messages')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '923001234567'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Thanks, your quote is ready.'
            && $request->hasHeader('Authorization', 'Bearer EAAG-test-access-token'));

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('wamid.OUT1', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_free_text_is_refused_once_the_window_has_closed(): void
    {
        $this->customerWroteAt(now()->subHours(25));

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('Send the approved follow-up message instead.');

        try {
            $this->messaging()->sendText($this->owner, $this->conversation, 'Hello again');
        } finally {
            $this->assertDatabaseCount('messages', 1);
            Queue::assertNothingPushed();
        }
    }

    public function test_the_approved_template_can_be_sent_after_the_window_closes(): void
    {
        $this->fakeMetaGraph();
        $this->customerWroteAt(now()->subDays(3));

        $message = $this->messaging()->sendTemplate($this->owner, $this->conversation, ['Sara', 'QT-1042']);

        $this->assertSame(['kind' => 'template', 'template' => 'quote_follow_up', 'language' => 'en', 'parameters' => ['Sara', 'QT-1042']],
            array_intersect_key($message->metadata, array_flip(['kind', 'template', 'language', 'parameters'])));

        $this->runQueuedSend($message);

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'quote_follow_up'
            && $request['template']['language']['code'] === 'en'
            && $request['template']['components'][0]['parameters'][1]['text'] === 'QT-1042');

        $this->assertSame(MessageStatus::Sent, $message->refresh()->status);
    }

    public function test_a_user_from_another_organization_cannot_send(): void
    {
        $this->customerWroteAt(now()->subHour());
        $stranger = User::factory()->owner()->create();

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('Conversation not found.');

        $this->messaging()->sendText($stranger, $this->conversation, 'Hi');
    }

    public function test_sending_requires_a_connected_number(): void
    {
        $this->customerWroteAt(now()->subHour());
        WhatsAppConnection::query()->delete();

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('Connect a WhatsApp Business number');

        $this->messaging()->sendText($this->owner, $this->conversation, 'Hi');
    }

    public function test_a_customer_without_a_phone_number_cannot_be_messaged(): void
    {
        $this->customer->forceFill(['phone_digits' => null])->save();

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('no phone number');

        $this->messaging()->sendTemplate($this->owner, $this->conversation, ['Sara']);
    }

    public function test_an_empty_or_oversized_text_is_refused(): void
    {
        $this->customerWroteAt(now()->subHour());

        $this->expectException(WhatsAppException::class);

        $this->messaging()->sendText($this->owner, $this->conversation, str_repeat('a', WhatsAppMessagingService::MAX_BODY + 1));
    }

    public function test_a_temporary_meta_failure_puts_the_message_back_in_the_queue_for_retry(): void
    {
        $this->customerWroteAt(now()->subHour());
        $message = $this->messaging()->sendText($this->owner, $this->conversation, 'Hi');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['error' => ['code' => 1, 'is_transient' => true]], 500)]);

        try {
            (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppApiInterface::class));
            $this->fail('A transient failure must be retried.');
        } catch (WhatsAppApiException $e) {
            $this->assertTrue($e->isTransient());
        }

        $this->assertSame(MessageStatus::Queued, $message->refresh()->status);
        $this->assertNull($message->provider_message_id);
    }

    public function test_a_rejected_message_is_marked_failed_without_leaking_the_token(): void
    {
        $this->customerWroteAt(now()->subHour());
        $message = $this->messaging()->sendText($this->owner, $this->conversation, 'Hi');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['error' => ['code' => 131030, 'message' => 'Recipient not in allowed list']], 400)]);

        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppApiInterface::class));

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('WhatsApp did not accept this message. Check the number and try again.', $message->failure_reason);
        $this->assertStringNotContainsString('EAAG', (string) $message->failure_reason);
    }

    public function test_a_job_never_sends_a_message_that_is_not_queued(): void
    {
        $this->fakeMetaGraph();
        $this->customerWroteAt(now()->subHour());
        $message = $this->messaging()->sendText($this->owner, $this->conversation, 'Hi');
        $message->forceFill(['status' => MessageStatus::Sent])->save();

        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppApiInterface::class));

        Http::assertNothingSent();
    }

    public function test_the_window_is_measured_from_the_customers_last_message(): void
    {
        $window = app(WhatsAppWindow::class);

        $this->assertFalse($window->isOpen($this->customer));

        $this->customerWroteAt(now()->subHours(23));
        $this->assertTrue($window->isOpen($this->customer));

        $this->customerWroteAt(now()->subHours(25));
        $this->assertTrue($window->isOpen($this->customer), 'The newest message decides.');
    }
}

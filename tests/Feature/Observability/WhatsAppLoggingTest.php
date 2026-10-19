<?php

namespace Tests\Feature\Observability;

use App\Contracts\WhatsApp\WhatsAppApiInterface;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\WhatsAppMessagingService;
use App\Services\WhatsApp\WhatsAppWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\WhatsAppPayloads;
use Tests\TestCase;

class WhatsAppLoggingTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppPayloads;

    private const CUSTOMER_PHONE = '923001234567';

    private const MESSAGE_TEXT = 'Yes, please go ahead';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWhatsApp();
        Queue::fake();

        $this->owner = User::factory()->owner()->create();
        $customer = Customer::factory()->for($this->owner->organization)->create();
        $customer->forceFill(['phone_digits' => self::CUSTOMER_PHONE])->save();
        Conversation::factory()->for($customer)->create(['organization_id' => $this->owner->organization_id]);
        $this->connectWhatsApp($this->owner->organization_id);
    }

    public function test_a_processed_inbound_message_is_logged_as_an_event_without_the_phone_number_or_text(): void
    {
        Log::spy();

        $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars(['CONTENT_TYPE' => 'application/json'] + $this->signedHeaders($payload = $this->inboundTextPayload(self::CUSTOMER_PHONE))), (string) json_encode($payload));
        app(WhatsAppWebhookProcessor::class)->process(WebhookEvent::sole());

        Log::shouldHaveReceived('info')->with('webhook.processed', Mockery::on(function (array $context) {
            $json = (string) json_encode($context);

            return $context['event'] === 'webhook.processed'
                && $context['outcome'] === 'processed'
                && $context['provider'] === 'whatsapp'
                && ! str_contains($json, self::CUSTOMER_PHONE)
                && ! str_contains($json, self::MESSAGE_TEXT);
        }))->once();
    }

    public function test_a_rejected_send_is_logged_with_its_event_name_and_never_the_access_token(): void
    {
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['error' => ['code' => 131030]], 400)]);
        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['last_message_at' => now()])->save();
        $this->recordCustomerMessage($conversation);

        Log::spy();

        $message = app(WhatsAppMessagingService::class)->sendText($this->owner, $conversation, 'Hello');
        (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppApiInterface::class));

        Log::shouldHaveReceived('warning')->with('whatsapp.send_rejected', Mockery::on(function (array $context) {
            return $context['event'] === 'whatsapp.send_rejected'
                && $context['retryable'] === false
                && ! str_contains((string) json_encode($context), 'EAAG-test-access-token');
        }))->once();
        $this->assertSame(MessageStatus::Failed, $message->refresh()->status);
    }

    private function recordCustomerMessage(Conversation $conversation): void
    {
        $message = new Message;
        $message->forceFill([
            'organization_id' => $this->owner->organization_id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::WhatsApp,
            'provider' => 'meta',
            'from_address' => '+'.self::CUSTOMER_PHONE,
            'to_address' => self::WA_DISPLAY_NUMBER,
            'subject' => '',
            'body_text' => self::MESSAGE_TEXT,
            'provider_message_id' => 'wamid.LOGTEST',
            'status' => MessageStatus::Received,
            'received_at' => now(),
        ])->save();
    }
}

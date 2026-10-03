<?php

namespace Tests\Feature\Automation;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\CustomerReplyClassified;
use App\Events\CustomerReplyReceived;
use App\Events\EstimateExpired;
use App\Events\EstimateSent;
use App\Events\EstimateViewed;
use App\Events\FollowUpDue;
use App\Exceptions\AI\ClassificationFailedException;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Services\AI\CustomerReplyClassificationService;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Email\ReplyRouteService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\Fakes\FakeReplyClassifier;
use Tests\TestCase;

class AutomationEventsTest extends TestCase
{
    use PostmarkInboundPayloads;
    use RefreshDatabase;

    private FakeReplyClassifier $classifier;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $classifier = $this->classifier = new FakeReplyClassifier;
        app(ReplyClassifierManager::class)->extend('openai', fn () => $classifier);

        $customer = Customer::factory()->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($customer)->create(['organization_id' => $customer->organization_id]);
    }

    private function reply(): Message
    {
        $message = new Message;
        $message->forceFill([
            'organization_id' => $this->conversation->organization_id,
            'conversation_id' => $this->conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => 'email',
            'provider' => 'postmark',
            'from_address' => 'john@example.com',
            'to_address' => 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai',
            'subject' => 'Re: estimate',
            'body_text' => "Yes, let's schedule it.",
            'provider_message_id' => fake()->uuid(),
            'status' => MessageStatus::Received,
            'received_at' => now(),
        ])->save();

        return $message;
    }

    private function classify(Message $message, bool $reclassify = false): void
    {
        $job = $reclassify ? ClassifyCustomerReplyJob::reclassification($message) : ClassifyCustomerReplyJob::automatic($message);
        $job->handle(app(CustomerReplyClassificationService::class));
    }

    // Structure

    public function test_customer_reply_classified_carries_ids_intent_and_confidence_only(): void
    {
        $event = new CustomerReplyClassified(1, 2, 3, 4, 5, CustomerReplyIntent::ReadyToBook, 0.97);

        $this->assertInstanceOf(AutomationEvent::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame(1, $event->organizationId());
        $this->assertSame(AutomationTriggerType::CustomerReplyClassified, $event->triggerType());
        $this->assertSame('classification:5', $event->eventId());
        $this->assertSame(
            ['organizationId', 'messageId', 'conversationId', 'customerId', 'classificationId', 'intent', 'confidence'],
            array_keys(get_object_vars($event)),
        );
    }

    public function test_every_automation_event_maps_to_its_trigger(): void
    {
        $events = [
            [new CustomerReplyReceived(1, 10, 3, 4), AutomationTriggerType::CustomerReplyReceived, 'message:10'],
            [new EstimateSent(1, 7), AutomationTriggerType::EstimateSent, 'estimate:7'],
            [new EstimateViewed(1, 7), AutomationTriggerType::EstimateViewed, 'estimate:7'],
            [new EstimateExpired(1, 7), AutomationTriggerType::EstimateExpired, 'estimate:7'],
            [new FollowUpDue(1, 9), AutomationTriggerType::FollowUpDue, 'follow_up:9'],
        ];

        foreach ($events as [$event, $trigger, $eventId]) {
            $this->assertInstanceOf(AutomationEvent::class, $event);
            $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
            $this->assertSame($trigger, $event->triggerType());
            $this->assertSame($eventId, $event->eventId());
            $this->assertSame(1, $event->organizationId());
        }
    }

    // Task 6 integration

    public function test_successful_classification_dispatches_customer_reply_classified(): void
    {
        Event::fake([CustomerReplyClassified::class]);
        $message = $this->reply();
        $this->classifier->willReturn(CustomerReplyIntent::ReadyToBook, 0.97);

        $this->classify($message);

        $classification = MessageClassification::sole();
        Event::assertDispatchedTimes(CustomerReplyClassified::class, 1);
        Event::assertDispatched(CustomerReplyClassified::class, fn (CustomerReplyClassified $event) => $event->organizationId === $this->conversation->organization_id
            && $event->messageId === $message->id
            && $event->conversationId === $this->conversation->id
            && $event->customerId === $this->conversation->customer_id
            && $event->classificationId === $classification->id
            && $event->intent === CustomerReplyIntent::ReadyToBook
            && $event->confidence === 0.97);
    }

    public function test_failed_classification_does_not_dispatch(): void
    {
        Event::fake([CustomerReplyClassified::class]);
        $message = $this->reply();
        $this->classifier
            ->willFail(ClassificationFailedException::invalidOutput('unknown intent'))
            ->willFail(ClassificationFailedException::unavailable('HTTP 503'));

        $this->classify($message);
        try {
            $this->classify($message);
        } catch (ClassificationFailedException) {
        }

        $this->assertSame(2, MessageClassification::where('status', 'failed')->count());
        Event::assertNotDispatched(CustomerReplyClassified::class);
    }

    public function test_duplicate_jobs_do_not_dispatch_twice_but_reclassification_does(): void
    {
        Event::fake([CustomerReplyClassified::class]);
        $message = $this->reply();
        $this->classifier->willReturn(CustomerReplyIntent::Interested, 0.7)->willReturn(CustomerReplyIntent::ReadyToBook, 0.95);

        $this->classify($message);
        $this->classify($message); // duplicate automatic job: no new classification
        $this->classify($message, reclassify: true);

        Event::assertDispatchedTimes(CustomerReplyClassified::class, 2);
        $ids = MessageClassification::orderBy('id')->pluck('id')->all();
        Event::assertDispatched(CustomerReplyClassified::class, fn ($e) => $e->classificationId === $ids[1] && $e->intent === CustomerReplyIntent::ReadyToBook);
    }

    public function test_event_is_only_dispatched_after_the_transaction_commits(): void
    {
        $received = [];
        Event::listen(CustomerReplyClassified::class, function (CustomerReplyClassified $event) use (&$received) {
            $received[] = $event;
        });

        try {
            DB::transaction(function () use (&$received) {
                event(new CustomerReplyClassified(1, 2, 3, 4, 5, CustomerReplyIntent::Interested, 0.9));
                $this->assertSame([], $received, 'Not delivered inside the transaction.');

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $received, 'Discarded when the transaction rolls back.');

        DB::transaction(fn () => event(new CustomerReplyClassified(1, 2, 3, 4, 6, CustomerReplyIntent::Interested, 0.9)));
        $this->assertCount(1, $received);
    }

    // Task 5 integration

    public function test_inbound_reply_from_the_customer_dispatches_customer_reply_received_once(): void
    {
        Event::fake([CustomerReplyReceived::class]);
        Queue::fake([ClassifyCustomerReplyJob::class]);
        $this->configureInboundWebhook();
        $replyTo = app(ReplyRouteService::class)->createFor($this->conversation);

        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo), $this->webhookAuth())->assertOk();
        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo), $this->webhookAuth())->assertOk(); // retried delivery

        $message = Message::where('direction', 'inbound')->sole();
        Event::assertDispatchedTimes(CustomerReplyReceived::class, 1);
        Event::assertDispatched(CustomerReplyReceived::class, fn (CustomerReplyReceived $event) => $event->organizationId === $this->conversation->organization_id
            && $event->messageId === $message->id
            && $event->conversationId === $this->conversation->id
            && $event->customerId === $this->conversation->customer_id);
    }

    public function test_reply_from_an_unexpected_sender_does_not_dispatch(): void
    {
        Event::fake([CustomerReplyReceived::class]);
        $this->configureInboundWebhook();
        $replyTo = app(ReplyRouteService::class)->createFor($this->conversation);

        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo, ['From' => 'x@evil.test', 'FromFull' => ['Email' => 'x@evil.test']]), $this->webhookAuth())->assertOk();

        Event::assertNotDispatched(CustomerReplyReceived::class);
    }

    public function test_estimate_and_follow_up_events_are_not_dispatched_by_the_application_yet(): void
    {
        Event::fake([EstimateSent::class, EstimateViewed::class, EstimateExpired::class, FollowUpDue::class]);
        $message = $this->reply();
        $this->classifier->willReturn();

        $this->classify($message);

        Event::assertNotDispatched(EstimateSent::class);
        Event::assertNotDispatched(EstimateViewed::class);
        Event::assertNotDispatched(EstimateExpired::class);
        Event::assertNotDispatched(FollowUpDue::class);
    }
}

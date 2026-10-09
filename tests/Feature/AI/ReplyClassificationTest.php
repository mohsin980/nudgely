<?php

namespace Tests\Feature\AI;

use App\Enums\ClassificationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\ReplySentiment;
use App\Enums\ReplyUrgency;
use App\Exceptions\AI\ClassificationFailedException;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Jobs\SendEmailJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Services\AI\CustomerReplyClassificationService;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Email\ReplyRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\Fakes\FakeReplyClassifier;
use Tests\TestCase;

class ReplyClassificationTest extends TestCase
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
        $this->configureInboundWebhook();

        $customer = Customer::factory()->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($customer)->create(['organization_id' => $customer->organization_id, 'subject' => 'HVAC Estimate']);
    }

    private function service(): CustomerReplyClassificationService
    {
        return app(CustomerReplyClassificationService::class);
    }

    private function outbound(string $text, ?Conversation $conversation = null): Message
    {
        $conversation ??= $this->conversation;
        $connection = EmailConnection::factory()->for($conversation->organization)->verified()->create(['sender_name' => 'Dallas Cooling']);

        return Message::factory()->create([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'email_connection_id' => $connection->id,
            'to_address' => 'john@example.com',
            'body_text' => $text,
            'status' => MessageStatus::Sent,
            'sent_at' => now()->subHours(2),
        ]);
    }

    private function reply(string $text = 'Can you do it for $5,800?', array $attributes = []): Message
    {
        $message = new Message;
        $message->forceFill(array_merge([
            'organization_id' => $this->conversation->organization_id,
            'conversation_id' => $this->conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => 'email',
            'provider' => 'postmark',
            'from_address' => 'john@example.com',
            'from_name' => 'John Smith',
            'to_address' => 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai',
            'subject' => 'Re: Your estimate',
            'body_text' => $text,
            'provider_message_id' => fake()->uuid(),
            'status' => MessageStatus::Received,
            'received_at' => now(),
        ], $attributes))->save();

        return $message;
    }

    // Trigger

    public function test_an_inbound_customer_reply_queues_classification(): void
    {
        Queue::fake([ClassifyCustomerReplyJob::class]);
        $replyTo = app(ReplyRouteService::class)->createFor($this->conversation);

        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo), $this->webhookAuth())->assertOk();

        $message = Message::where('direction', 'inbound')->sole();
        Queue::assertPushed(ClassifyCustomerReplyJob::class, fn (ClassifyCustomerReplyJob $job) => $job->messageId === $message->id
            && $job->requestId === 'auto-'.$message->id
            && $job->reclassify === false);
        $this->assertSame([], $this->classifier->contexts, 'The AI is never called during webhook handling.');
    }

    public function test_replies_held_for_review_are_not_classified(): void
    {
        Queue::fake([ClassifyCustomerReplyJob::class]);
        $replyTo = app(ReplyRouteService::class)->createFor($this->conversation);

        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo, ['From' => 'x@evil.test', 'FromFull' => ['Email' => 'x@evil.test', 'Name' => 'X']]), $this->webhookAuth());

        $this->assertSame(MessageStatus::NeedsReview, Message::where('direction', 'inbound')->sole()->status);
        Queue::assertNotPushed(ClassifyCustomerReplyJob::class);
    }

    public function test_classification_can_be_disabled(): void
    {
        Queue::fake([ClassifyCustomerReplyJob::class]);
        config(['ai.classification.enabled' => false]);
        $replyTo = app(ReplyRouteService::class)->createFor($this->conversation);

        $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo), $this->webhookAuth());

        Queue::assertNotPushed(ClassifyCustomerReplyJob::class);
    }

    // Storage

    public function test_reply_is_classified_and_every_field_is_stored(): void
    {
        $message = $this->reply();
        $this->classifier->willReturn(CustomerReplyIntent::PriceObjection, 0.94, 'Customer is interested but is negotiating the quoted price.', ReplySentiment::Neutral, ReplyUrgency::Medium, false, 'gpt-test');

        (new ClassifyCustomerReplyJob($message->id, 'auto-'.$message->id))->handle($this->service());

        $classification = MessageClassification::sole();
        $this->assertSame(ClassificationStatus::Succeeded, $classification->status);
        $this->assertSame(CustomerReplyIntent::PriceObjection, $classification->intent);
        $this->assertSame(0.94, $classification->confidence);
        $this->assertSame('Customer is interested but is negotiating the quoted price.', $classification->summary);
        $this->assertSame(ReplySentiment::Neutral, $classification->sentiment);
        $this->assertSame(ReplyUrgency::Medium, $classification->urgency);
        // price_objection is always reviewed by policy, even though the AI said false.
        $this->assertTrue($classification->requires_human_review);
        $this->assertSame('gpt-test', $classification->model);
        $this->assertNotNull($classification->classified_at);

        // Isolation: the classification, message and conversation all belong together.
        $this->assertSame($message->id, $classification->message_id);
        $this->assertSame($this->conversation->id, $classification->conversation_id);
        $this->assertSame($this->conversation->organization_id, $classification->organization_id);
        $this->assertSame($message->organization_id, $classification->organization_id);

        $this->conversation->refresh();
        $this->assertSame(CustomerReplyIntent::PriceObjection, $this->conversation->latest_intent);
        $this->assertTrue($this->conversation->needs_attention);
    }

    public function test_classification_never_acts_on_the_customer(): void
    {
        Queue::fake();
        $message = $this->reply('Yes, let\'s get this scheduled for next Tuesday.');
        $this->classifier->willReturn(CustomerReplyIntent::ReadyToBook, 0.97);
        $messagesBefore = Message::count();

        $this->service()->classify($message, 'auto-'.$message->id);

        $this->assertSame($messagesBefore, Message::count());
        Queue::assertNotPushed(SendEmailJob::class);
        $this->assertFalse($this->conversation->fresh()->needs_attention);
    }

    // Confidence and review

    public function test_low_confidence_requires_review(): void
    {
        $message = $this->reply('Thanks, I\'ll let you know.');
        $this->classifier->willReturn(CustomerReplyIntent::Interested, 0.42, requiresHumanReview: false);

        $this->assertTrue($this->service()->classify($message, 'auto-'.$message->id)->requires_human_review);
    }

    public function test_high_confidence_result_is_not_flagged(): void
    {
        $message = $this->reply('Does the estimate include installation?');
        $this->classifier->willReturn(CustomerReplyIntent::Question, 0.93, requiresHumanReview: false);

        $classification = $this->service()->classify($message, 'auto-'.$message->id);

        $this->assertFalse($classification->requires_human_review);
        $this->assertFalse($this->conversation->fresh()->needs_attention);
    }

    // Failures and retries

    public function test_invalid_ai_output_records_a_failure_without_a_fake_classification(): void
    {
        $message = $this->reply();
        $this->classifier->willFail(ClassificationFailedException::invalidOutput('unknown intent'));

        // Permanent failure: the job finishes normally, so the queue will not retry it.
        (new ClassifyCustomerReplyJob($message->id, 'auto-'.$message->id))->handle($this->service());

        $attempt = MessageClassification::sole();
        $this->assertSame(ClassificationStatus::Failed, $attempt->status);
        $this->assertNull($attempt->intent);
        $this->assertSame('AI returned an invalid classification: unknown intent.', $attempt->failure_reason);
        $this->assertNull($this->conversation->fresh()->latest_intent);
    }

    public function test_transient_failures_are_rethrown_for_retry_and_can_then_succeed(): void
    {
        $message = $this->reply();
        $this->classifier->willFail(ClassificationFailedException::unavailable('HTTP 429'))->willReturn(CustomerReplyIntent::PriceObjection, 0.9);
        $job = new ClassifyCustomerReplyJob($message->id, 'auto-'.$message->id);

        try {
            $job->handle($this->service());
            $this->fail('Expected exception.');
        } catch (ClassificationFailedException $e) {
            $this->assertTrue($e->transient);
        }

        $job->handle($this->service());

        $this->assertSame(1, MessageClassification::where('status', 'failed')->count());
        $this->assertSame(1, MessageClassification::where('status', 'succeeded')->count());
    }

    public function test_job_retry_settings_are_bounded(): void
    {
        $job = new ClassifyCustomerReplyJob(1, 'auto-1');

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertSame(90, $job->timeout);
        $this->assertSame('auto-1', $job->uniqueId());
    }

    // Idempotency and history

    public function test_duplicate_jobs_do_not_create_duplicate_classifications(): void
    {
        $message = $this->reply();
        $this->classifier->willReturn()->willReturn();
        $job = ClassifyCustomerReplyJob::automatic($message);

        $job->handle($this->service());
        $job->handle($this->service());
        (new ClassifyCustomerReplyJob($message->id, 'auto-'.$message->id))->handle($this->service());

        $this->assertSame(1, MessageClassification::count());
        $this->assertCount(1, $this->classifier->contexts, 'The AI is called once.');
    }

    public function test_reclassification_adds_a_new_record_and_keeps_history(): void
    {
        $message = $this->reply();
        $this->classifier
            ->willReturn(CustomerReplyIntent::PriceObjection, 0.71, model: 'model-a')
            ->willReturn(CustomerReplyIntent::Interested, 0.91, model: 'model-b');

        ClassifyCustomerReplyJob::automatic($message)->handle($this->service());
        $reclassify = ClassifyCustomerReplyJob::reclassification($message);
        $reclassify->handle($this->service());
        $reclassify->handle($this->service()); // duplicate delivery of the same reclassify request

        $history = MessageClassification::orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame(['model-a', 'model-b'], $history->pluck('model')->all());
        $this->assertSame(CustomerReplyIntent::Interested, $message->classifications()->succeeded()->first()->intent);
        $this->assertSame(CustomerReplyIntent::Interested, $this->conversation->fresh()->latest_intent);
    }

    public function test_classifying_an_older_reply_does_not_override_the_conversation_state(): void
    {
        $older = $this->reply('Can you lower the price?', ['received_at' => now()->subDay()]);
        $latest = $this->reply('Ok, let\'s book it.');
        $this->classifier->willReturn(CustomerReplyIntent::ReadyToBook, 0.97)->willReturn(CustomerReplyIntent::PriceObjection, 0.9);

        $this->service()->classify($latest, 'auto-'.$latest->id);
        $this->service()->classify($older, 'auto-'.$older->id);

        $this->assertSame(CustomerReplyIntent::ReadyToBook, $this->conversation->fresh()->latest_intent);
    }

    // Isolation and eligibility

    public function test_messages_that_are_not_valid_customer_replies_are_never_sent_to_the_ai(): void
    {
        $outbound = $this->outbound('Hi John');
        $review = $this->reply('hijack', ['conversation_id' => null, 'status' => MessageStatus::NeedsReview]);
        $foreignConversation = Conversation::factory()->create();
        $crossTenant = withoutTenantTriggers('messages', fn () => $this->reply('cross tenant', ['conversation_id' => $foreignConversation->id]));

        foreach ([$outbound, $review, $crossTenant] as $message) {
            $this->assertNull($this->service()->classify($message, 'auto-'.$message->id));
        }

        $this->assertSame([], $this->classifier->contexts);
        $this->assertSame(0, MessageClassification::count());
    }

    // Data minimization

    public function test_only_minimal_context_is_sent_to_the_ai(): void
    {
        config(['ai.classification.context.previous_messages' => 2, 'ai.classification.context.max_previous_chars' => 30]);
        $this->outbound('First message from long ago that should be dropped entirely.');
        $this->outbound('Hi John, just following up on your $6,500 HVAC estimate. Let us know!');
        $this->reply('Earlier question?', ['received_at' => now()->subHour()]);
        $message = $this->reply("Can you do it for \$5,800?\n\nOn Thu, Oct 1, 2026 at 9:00 AM Dallas Cooling <sales@example.com> wrote:\n> Hi John, just following up\n> on your estimate");
        $this->classifier->willReturn();

        $this->service()->classify($message, 'auto-'.$message->id);

        $context = $this->classifier->contexts[0];
        $this->assertSame('Dallas Cooling', $context->businessName);
        $this->assertSame('John', $context->customerFirstName);
        $this->assertSame('HVAC Estimate', $context->topic);
        $this->assertSame('Can you do it for $5,800?', $context->latestReply);
        $this->assertCount(2, $context->previousMessages);
        $this->assertSame('business', $context->previousMessages[0]['from']);
        $this->assertLessThanOrEqual(31, mb_strlen($context->previousMessages[0]['text']));
        $this->assertSame(['from' => 'customer', 'text' => 'Earlier question?'], $context->previousMessages[1]);

        $serialized = json_encode($context);
        $this->assertStringNotContainsString('john@example.com', $serialized);
        $this->assertStringNotContainsString('sales@example.com', $serialized);
        $this->assertStringNotContainsString('Smith', $serialized);
        $this->assertStringNotContainsString('dropped entirely', $serialized);
    }
}

<?php

namespace Tests\Feature\Automation;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\TaskPriority;
use App\Events\CustomerReplyClassified;
use App\Jobs\SendEmailJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use App\Services\Email\ReplyRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\Fakes\FakeReplyClassifier;
use Tests\TestCase;

/**
 * Customer reply (Postmark webhook) → AI classification → automation engine → actions,
 * with only the AI provider faked and email delivery left queued.
 */
class AutomationEndToEndTest extends TestCase
{
    use PostmarkInboundPayloads;
    use RefreshDatabase;

    private FakeReplyClassifier $classifier;

    private User $admin;

    private Conversation $conversation;

    private int $replies = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendEmailJob::class]);
        config(['ai.classification.enabled' => true]);
        $this->configureInboundWebhook();
        $classifier = $this->classifier = new FakeReplyClassifier;
        app(ReplyClassifierManager::class)->extend('openai', fn () => $classifier);

        $this->admin = User::factory()->admin()->create();
        $this->admin->organization->update(['name' => 'Dallas Cooling']);
        EmailConnection::factory()->verified()->default()->create(['organization_id' => $this->admin->organization_id, 'domain' => 'example.com', 'sender_email' => 'sales@example.com', 'sender_name' => 'Dallas Cooling']);
        $customer = Customer::factory()->for($this->admin->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($customer)->create(['organization_id' => $this->admin->organization_id, 'subject' => 'HVAC Estimate']);
    }

    private function activateTemplate(string $key): Automation
    {
        $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, $key);
        app(AutomationBuilder::class)->activate($automation, $this->admin);

        return $automation;
    }

    /**
     * Deliver a customer reply through the inbound webhook; returns the payload so it can be redelivered.
     */
    private function customerReplies(CustomerReplyIntent $intent, float $confidence = 0.95, ?array $payload = null): array
    {
        $this->classifier->willReturn($intent, $confidence);
        $payload ??= $this->postmarkInbound(app(ReplyRouteService::class)->createFor($this->conversation), ['MessageID' => 'reply-'.++$this->replies]);

        $this->postJson('/webhooks/email/inbound/postmark', $payload, $this->webhookAuth())->assertOk();

        return $payload;
    }

    public function test_ready_to_book_reply_runs_the_template_once_even_when_delivered_twice(): void
    {
        $automation = $this->activateTemplate('ready_to_book');

        $payload = $this->customerReplies(CustomerReplyIntent::ReadyToBook, 0.95);
        $this->customerReplies(CustomerReplyIntent::ReadyToBook, 0.95, $payload); // webhook retried
        event(CustomerReplyClassified::fromClassification(MessageClassification::sole(), $this->conversation->customer_id)); // event redelivered

        $run = AutomationRun::sole();
        $this->assertSame($automation->id, $run->automation_id);
        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertSame($this->conversation->id, $run->conversation_id);
        $this->assertTrue($run->actionRuns->every(fn ($r) => $r->status === AutomationActionRunStatus::Completed));

        $task = Task::sole();
        $this->assertSame('Book John Smith', $task->title);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame(['ready-to-book'], $this->conversation->customer->tags()->pluck('slug')->all());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->admin->id)->count());
        $this->assertSame(0, Message::where('direction', 'outbound')->count(), 'Templates never email customers.');
    }

    public function test_low_confidence_or_other_intents_do_not_trigger_ready_to_book(): void
    {
        $this->activateTemplate('ready_to_book');

        $this->customerReplies(CustomerReplyIntent::ReadyToBook, 0.6);
        $this->customerReplies(CustomerReplyIntent::Question, 0.99);

        $this->assertSame(0, AutomationRun::count());
        $this->assertSame(0, Task::count());
    }

    public function test_callback_template_marks_the_conversation_waiting_on_the_business(): void
    {
        $this->activateTemplate('wants_callback');

        $this->customerReplies(CustomerReplyIntent::WantsCallback, 0.9);

        $this->assertSame(ConversationStatus::WaitingBusiness, $this->conversation->refresh()->status);
        $this->assertSame('Call back John Smith', Task::sole()->title);
    }

    public function test_interested_follow_up_is_skipped_after_the_customer_replies(): void
    {
        $this->activateTemplate('interested');

        $this->customerReplies(CustomerReplyIntent::Interested, 0.9);
        $followUp = FollowUp::sole();
        $this->assertSame(FollowUpStatus::Pending, $followUp->status);

        $this->travel(1)->day();
        $this->customerReplies(CustomerReplyIntent::Question, 0.9);
        $this->travel(2)->days();
        $this->artisan('follow-ups:process-due')->assertSuccessful();

        $followUp->refresh();
        $this->assertSame(FollowUpStatus::Skipped, $followUp->status);
        $this->assertSame(FollowUpSkipReason::CustomerReplied, $followUp->skip_reason);
        $this->assertSame(0, Message::where('direction', 'outbound')->count());
    }

    public function test_a_controlled_email_is_sent_from_the_verified_business_address_only_when_enabled(): void
    {
        $automation = app(AutomationBuilder::class)->save($this->admin->organization, $this->admin, [
            'name' => 'Thank interested customers',
            'trigger_type' => 'customer_reply_classified',
            'conditions' => [['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'interested']],
            'actions' => [['type' => 'send_email', 'requires_approval' => false, 'configuration' => [
                'subject' => 'Thanks, {{customer.first_name}}',
                'body' => "Hi {{customer.first_name}},\nThanks for your interest. We'll be in touch.\n{{business.name}}",
            ]]],
        ]);
        app(AutomationBuilder::class)->activate($automation, $this->admin);

        // Defaults: automatic emails off.
        $this->customerReplies(CustomerReplyIntent::Interested);
        $this->assertSame('automatic_email_disabled', AutomationRun::sole()->actionRuns->sole()->result['data']['reason']);
        $this->assertSame(0, Message::where('direction', 'outbound')->count());

        $this->admin->organization->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();
        $this->customerReplies(CustomerReplyIntent::Interested);

        $email = Message::where('direction', 'outbound')->sole();
        $this->assertSame('sales@example.com', $email->from_address);
        $this->assertSame('john@example.com', $email->to_address);
        $this->assertSame('Thanks, John', $email->subject);
        $this->assertSame("Hi John,\nThanks for your interest. We'll be in touch.\nDallas Cooling", $email->body_text);
        $this->assertStringStartsWith('reply+', $email->reply_to);
        $this->assertSame($this->conversation->id, $email->conversation_id);
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->messageId === $email->id);
    }
}

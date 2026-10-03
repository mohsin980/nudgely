<?php

namespace Tests\Feature\Automation;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\CustomerReplyReceived;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Jobs\ProcessFollowUpJob;
use App\Jobs\SendEmailJob;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationActionManager;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\EmailTemplateRenderer;
use App\Services\Automation\FollowUpProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Conversation $conversation;

    private Automation $automation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->admin->organization->update(['name' => 'Dallas Cooling']);
        $this->customer = Customer::factory()->for($this->admin->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->admin->organization_id, 'subject' => 'HVAC Estimate']);
        $this->automation = Automation::factory()->active()->create(['organization_id' => $this->admin->organization_id, 'trigger_type' => AutomationTriggerType::CustomerReplyClassified]);
    }

    private function followUpAction(array $configuration = []): AutomationAction
    {
        return $this->automation->actions()->create(['type' => AutomationActionType::ScheduleFollowUp, 'configuration' => $configuration + [
            'delay_days' => 2,
            'subject' => 'Following up, {{customer.first_name}}',
            'body' => "Hi {{customer.first_name}},\nAny questions?\n{{business.name}}",
        ]]);
    }

    private function context(string $eventId = 'classification:1'): AutomationContext
    {
        return new AutomationContext($this->admin->organization_id, AutomationTriggerType::CustomerReplyClassified, $eventId,
            customerId: $this->customer->id, conversationId: $this->conversation->id, intent: CustomerReplyIntent::Interested, confidence: 0.9);
    }

    private function schedule(?AutomationAction $action = null, string $eventId = 'classification:1'): FollowUp
    {
        $result = app(AutomationActionManager::class)->execute($action ?? $this->followUpAction(), $this->context($eventId));
        $this->assertTrue($result->success, $result->message);

        return FollowUp::findOrFail($result->data['follow_up_id']);
    }

    private function customerReply(): Message
    {
        $message = new Message;
        $message->forceFill([
            'organization_id' => $this->admin->organization_id, 'conversation_id' => $this->conversation->id,
            'direction' => MessageDirection::Inbound, 'channel' => 'email', 'provider' => 'postmark',
            'from_address' => 'john@example.com', 'to_address' => 'reply+'.str_repeat('b', 40).'@inbound.quoteflow.ai',
            'subject' => 'Re: HVAC Estimate', 'body_text' => 'Sounds good', 'provider_message_id' => fake()->uuid(),
            'status' => MessageStatus::Received, 'received_at' => now(),
        ])->save();

        return $message;
    }

    private function enableAutomaticEmail(): void
    {
        $this->admin->organization->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();
        EmailConnection::factory()->verified()->default()->create(['organization_id' => $this->admin->organization_id, 'domain' => 'example.com', 'sender_email' => 'sales@example.com']);
    }

    // Scheduling

    public function test_schedule_follow_up_creates_one_pending_follow_up_per_event(): void
    {
        $this->freezeSecond();
        $action = $this->followUpAction();

        $followUp = $this->schedule($action);
        $again = app(AutomationActionManager::class)->execute($action, $this->context());

        $this->assertSame(FollowUpStatus::Pending, $followUp->status);
        $this->assertTrue($followUp->due_at->equalTo(now()->addDays(2)));
        $this->assertSame([$this->admin->organization_id, $this->customer->id, $this->conversation->id, $this->automation->id], [$followUp->organization_id, $followUp->customer_id, $followUp->conversation_id, $followUp->automation_id]);
        $this->assertSame(AutomationActionRunStatus::Skipped, $again->status);
        $this->assertSame('already_exists', $again->data['reason']);
        $this->assertSame(1, FollowUp::count());
    }

    public function test_invalid_follow_up_configuration_is_rejected(): void
    {
        $manager = app(AutomationActionManager::class);

        $this->assertSame('delay_days must be a whole number from 1 to 60.', $manager->execute($this->followUpAction(['delay_days' => 0]), $this->context('e:1'))->message);
        $this->assertSame('Unsupported variable {{php_code}}.', $manager->execute($this->followUpAction(['body' => '{{php_code}}']), $this->context('e:2'))->message);
        $this->assertSame(0, FollowUp::count());
    }

    // Cancellation

    public function test_a_customer_reply_skips_pending_follow_ups(): void
    {
        $followUp = $this->schedule();
        $foreign = Conversation::factory()->create();

        event(new CustomerReplyReceived($this->admin->organization_id, 99, $this->conversation->id, $this->customer->id));
        // Another tenant's IDs never touch this organization's follow-ups.
        event(new CustomerReplyReceived($foreign->organization_id, 100, $this->conversation->id, $foreign->customer_id));

        $followUp->refresh();
        $this->assertSame(FollowUpStatus::Skipped, $followUp->status);
        $this->assertSame('Follow-up skipped: Customer replied.', $followUp->outcome);
    }

    public function test_a_reply_after_scheduling_skips_the_follow_up_at_due_time(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        $followUp = $this->schedule();

        $this->travel(1)->day();
        $this->customerReply(); // stored without raising the event
        $this->travel(2)->days();
        app(FollowUpProcessor::class)->process($followUp->id);

        $this->assertSame('Follow-up skipped: Customer replied.', $followUp->refresh()->outcome);
        $this->assertSame(0, Message::where('direction', 'outbound')->count());
    }

    // Processing at due time

    public function test_a_due_follow_up_is_emailed_through_the_email_service_when_allowed(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        $followUp = $this->schedule();

        $this->assertNull(app(FollowUpProcessor::class)->process($followUp->id), 'Not processed before it is due.');

        $this->travel(2)->days();
        $this->artisan('automations:process-follow-ups')->expectsOutput('1 due follow-up(s) queued.');
        app(FollowUpProcessor::class)->process($followUp->id); // a duplicate job does nothing

        $followUp->refresh();
        $message = Message::where('direction', 'outbound')->sole();
        $this->assertSame(FollowUpStatus::Completed, $followUp->status);
        $this->assertSame('Follow-up email sent.', $followUp->outcome);
        $this->assertSame($message->id, $followUp->message_id);
        $this->assertSame('sales@example.com', $message->from_address);
        $this->assertSame('Following up, John', $message->subject);
        $this->assertSame("Hi John,\nAny questions?\nDallas Cooling", $message->body_text);
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_when_automatic_email_is_off_a_reminder_task_is_created_instead(): void
    {
        Queue::fake([SendEmailJob::class]);
        $followUp = $this->schedule();

        $this->travel(3)->days();
        app(FollowUpProcessor::class)->process($followUp->id);

        $followUp->refresh();
        $this->assertSame(FollowUpStatus::Completed, $followUp->status);
        $this->assertSame('Follow up with John Smith', Task::findOrFail($followUp->task_id)->title);
        $this->assertSame(0, Message::count());
    }

    public function test_due_follow_ups_are_skipped_when_the_automation_is_paused_or_the_customer_opted_out(): void
    {
        $this->enableAutomaticEmail();
        $paused = $this->schedule(eventId: 'classification:1');
        $optedOut = $this->schedule(eventId: 'classification:2');

        $this->travel(3)->days();
        $this->automation->update(['status' => 'paused']);
        app(FollowUpProcessor::class)->process($paused->id);
        $this->automation->update(['status' => 'active']);
        $this->customer->forceFill(['email_opted_out_at' => now()])->save();
        app(FollowUpProcessor::class)->process($optedOut->id);

        $this->assertSame('Follow-up skipped: The automation is no longer active.', $paused->refresh()->outcome);
        $this->assertSame('Follow-up skipped: Customer opted out of email.', $optedOut->refresh()->outcome);
        $this->assertSame(0, Message::count());
    }

    public function test_the_scheduler_only_queues_due_pending_follow_ups(): void
    {
        Queue::fake();
        $due = $this->schedule(eventId: 'classification:1');
        $this->schedule(eventId: 'classification:2')->forceFill(['due_at' => now()->addWeek()])->save();
        $this->schedule(eventId: 'classification:3')->forceFill(['status' => FollowUpStatus::Skipped])->save();

        $this->travel(3)->days();
        $this->artisan('automations:process-follow-ups')->assertSuccessful();

        Queue::assertPushed(ProcessFollowUpJob::class, 1);
        Queue::assertPushed(ProcessFollowUpJob::class, fn ($job) => $job->followUpId === $due->id);
    }

    // Email templates

    public function test_email_templates_fill_only_controlled_variables(): void
    {
        $renderer = app(EmailTemplateRenderer::class);
        $organization = new Organization(['name' => 'Dallas Cooling']);
        $customer = new Customer(['name' => 'Mary  Ann Smith', 'email' => 'Mary@Example.com']);

        $this->assertSame(
            'Hi Mary (Ann Smith, mary@example.com) from Dallas Cooling',
            $renderer->render('Hi {{customer.first_name}} ({{ customer.last_name }}, {{customer.email}}) from {{business.name}}', $customer, $organization),
        );

        // Values are inserted once, never re-parsed.
        $sneaky = new Customer(['name' => '{{business.name}}', 'email' => 'x@example.com']);
        $this->assertSame('Hi {{business.name}}', $renderer->render('Hi {{customer.first_name}}', $sneaky, $organization));

        foreach ([
            '{{php_code}}' => 'Unsupported variable {{php_code}}.',
            '{{ system("ls") }}' => 'Unsupported variable {{system("ls")}}.',
            '{{business.phone}}' => '{{business.phone}} cannot be used yet: Business phone numbers are not stored yet.',
            '{{estimate.total}}' => '{{estimate.total}} cannot be used yet: Estimates are not available yet.',
            'Hi {{customer.first_name}' => 'The template has unmatched {{ or }} braces.',
        ] as $template => $error) {
            try {
                $renderer->validate($template);
                $this->fail("{$template} should be rejected.");
            } catch (InvalidEmailTemplateException $e) {
                $this->assertSame($error, $e->getMessage());
            }
        }
    }
}

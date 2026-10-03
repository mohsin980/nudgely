<?php

namespace Tests\Feature\Automation;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Events\CustomerReplyClassified;
use App\Events\CustomerReplyReceived;
use App\Exceptions\Automation\AutomationActionRetryException;
use App\Exceptions\Automation\TransientAutomationException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\EvaluateAutomationJob;
use App\Jobs\ExecuteAutomationActionJob;
use App\Jobs\SendEmailJob;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\Actions\CreateTaskAction;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationEngine;
use App\Services\Email\EmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Conversation $conversation;

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = Customer::factory()->for($this->admin->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->admin->organization_id, 'subject' => 'AC Replacement']);
    }

    private function organization(): Organization
    {
        return $this->admin->organization;
    }

    /**
     * An active "ready to book" automation with the given actions.
     *
     * @param  list<array{0: AutomationActionType, 1?: array<string, mixed>, 2?: bool}>  $actions
     */
    private function automation(array $actions = [[AutomationActionType::CreateTask, ['title' => 'Call {customer_name}']]], ?AutomationTriggerType $trigger = null, bool $withCondition = true): Automation
    {
        $automation = Automation::factory()->active()->create([
            'organization_id' => $this->admin->organization_id,
            'trigger_type' => $trigger ?? AutomationTriggerType::CustomerReplyClassified,
        ]);

        if ($withCondition) {
            $automation->conditions()->create(['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'ready_to_book']);
        }

        foreach ($actions as $i => $action) {
            $automation->actions()->create(array_filter([
                'type' => $action[0],
                'configuration' => $action[1] ?? [],
                'sort_order' => $i,
                'requires_approval' => $action[2] ?? null,
            ], fn ($value) => $value !== null));
        }

        return $automation;
    }

    private function classified(CustomerReplyIntent $intent = CustomerReplyIntent::ReadyToBook, float $confidence = 0.95, ?int $classificationId = null, ?Conversation $conversation = null): CustomerReplyClassified
    {
        $conversation ??= $this->conversation;
        $id = $classificationId ?? $this->nextId++;

        return new CustomerReplyClassified($this->admin->organization_id, $id, $conversation->id, $conversation->customer_id, $id, $intent, $confidence);
    }

    private function engine(): AutomationEngine
    {
        return app(AutomationEngine::class);
    }

    private function enableAutomaticEmail(): void
    {
        $this->organization()->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();
        EmailConnection::factory()->verified()->default()->create([
            'organization_id' => $this->admin->organization_id,
            'domain' => 'example.com',
            'sender_email' => 'sales@example.com',
        ]);
    }

    // Queueing

    public function test_events_queue_an_evaluation_job_instead_of_running_inline(): void
    {
        Queue::fake();
        $this->automation();

        event($this->classified(classificationId: 42));

        Queue::assertPushed(EvaluateAutomationJob::class, fn (EvaluateAutomationJob $job) => $job->depth === 0
            && $job->event instanceof CustomerReplyClassified
            && $job->event->eventId() === 'classification:42');
        $this->assertSame(0, AutomationRun::count(), 'Nothing runs until the job is processed.');
    }

    // Execution

    public function test_matching_automation_runs_its_actions_in_order_and_records_results(): void
    {
        $automation = $this->automation([
            [AutomationActionType::CreateTask, ['title' => 'Call {customer_name}', 'priority' => 'high']],
            [AutomationActionType::AddCustomerTag, ['tag' => 'Ready to book']],
            [AutomationActionType::NotifyUser, ['message' => '{customer_name} is ready to book']],
        ]);

        event($this->classified(classificationId: 7));

        $run = AutomationRun::sole();
        $this->assertSame($automation->id, $run->automation_id);
        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertSame('classification:7', $run->event_id);
        $this->assertSame(0, $run->depth);
        $this->assertEqualsCanonicalizing(['organization_id', 'trigger_type', 'event_id', 'customer_id', 'conversation_id', 'message_id', 'classification_id', 'intent', 'confidence', 'depth'], array_keys($run->context));
        $this->assertNotNull($run->completed_at);

        $actionRuns = $run->actionRuns;
        $this->assertSame(['create_task', 'add_customer_tag', 'notify_user'], $actionRuns->map(fn ($r) => $r->action_type->value)->all());
        $this->assertTrue($actionRuns->every(fn ($r) => $r->status === AutomationActionRunStatus::Completed));
        $this->assertSame($actionRuns->sortBy('executed_at')->pluck('id')->all(), $actionRuns->pluck('id')->all());
        $this->assertSame('Task created: Call John Smith', $actionRuns[0]->result['message']);

        $this->assertSame('Call John Smith', Task::sole()->title);
        $this->assertSame(['ready-to-book'], $this->customer->tags()->pluck('slug')->all());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_automations_whose_conditions_do_not_match_create_no_run(): void
    {
        $this->automation();

        event($this->classified(CustomerReplyIntent::NotInterested));

        $this->assertSame(0, AutomationRun::count());
        $this->assertSame(0, Task::count());
    }

    public function test_paused_draft_and_other_trigger_automations_are_ignored(): void
    {
        $this->automation()->forceFill(['status' => 'paused'])->save();
        $this->automation()->forceFill(['status' => 'draft'])->save();
        $this->automation(trigger: AutomationTriggerType::CustomerReplyReceived, withCondition: false);

        event($this->classified());

        $this->assertSame(0, AutomationRun::count());
    }

    public function test_an_invalid_condition_fails_the_run_with_the_reason(): void
    {
        $automation = $this->automation(withCondition: false);
        $automation->conditions()->create(['type' => 'customer_status_equals', 'operator' => 'equals', 'value' => 'lead']);

        event($this->classified());

        $run = AutomationRun::sole();
        $this->assertSame(AutomationRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not available', $run->failure_reason);
        $this->assertSame(0, Task::count());
    }

    // Idempotency

    public function test_the_same_event_delivered_twice_runs_each_automation_once(): void
    {
        $this->automation();
        $event = $this->classified(classificationId: 5);

        event($event);
        event($event);
        $this->engine()->evaluate($event);

        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(1, AutomationActionRun::count());
        $this->assertSame(1, Task::count());
    }

    public function test_a_concurrent_duplicate_is_stopped_by_the_unique_index(): void
    {
        Queue::fake();
        $automation = $this->automation();
        $event = $this->classified(classificationId: 9);

        $this->assertCount(1, $this->engine()->evaluate($event));
        $this->assertSame([], $this->engine()->evaluate($event), 'The losing worker creates nothing.');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('automation_runs')->insert([
            'organization_id' => $automation->organization_id, 'automation_id' => $automation->id,
            'event_type' => 'customer_reply_classified', 'event_id' => 'classification:9', 'status' => 'running',
        ]);
    }

    public function test_an_action_run_executes_only_once_even_if_its_job_is_delivered_twice(): void
    {
        Queue::fake();
        $this->automation();
        $this->engine()->evaluate($this->classified());
        $actionRun = AutomationActionRun::sole();

        $this->engine()->executeActionRun($actionRun->id);
        $this->assertNull($this->engine()->executeActionRun($actionRun->id));

        $this->assertSame(1, Task::count());
        $this->assertSame(AutomationActionRunStatus::Completed, $actionRun->refresh()->status);
    }

    // Retries

    private function failingTaskHandler(int $transientFailures): void
    {
        $this->app->instance(CreateTaskAction::class, new class($transientFailures) implements AutomationActionInterface
        {
            public function __construct(public int $failuresLeft) {}

            public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
            {
                if ($this->failuresLeft-- > 0) {
                    throw new TransientAutomationException('provider timeout');
                }

                return AutomationActionResult::completed('Done.');
            }
        });
    }

    public function test_a_temporary_failure_is_retried_and_then_succeeds(): void
    {
        Queue::fake();
        $this->failingTaskHandler(1);
        $this->automation();
        $this->engine()->evaluate($this->classified());
        $actionRun = AutomationActionRun::sole();

        try {
            $this->engine()->executeActionRun($actionRun->id, finalAttempt: false);
            $this->fail('A temporary failure should be retried.');
        } catch (AutomationActionRetryException) {
        }

        $this->assertSame(AutomationActionRunStatus::Pending, $actionRun->refresh()->status);
        $this->assertSame(AutomationRunStatus::Running, $actionRun->run->status);

        $this->engine()->executeActionRun($actionRun->id, finalAttempt: false);

        $this->assertSame(AutomationActionRunStatus::Completed, $actionRun->refresh()->status);
        $this->assertSame(AutomationRunStatus::Completed, $actionRun->run->refresh()->status);
    }

    public function test_a_temporary_failure_on_the_last_attempt_is_recorded(): void
    {
        Queue::fake();
        $this->failingTaskHandler(5);
        $this->automation();
        $this->engine()->evaluate($this->classified());
        $actionRun = AutomationActionRun::sole();

        $this->engine()->executeActionRun($actionRun->id, finalAttempt: true);

        $actionRun->refresh();
        $this->assertSame(AutomationActionRunStatus::Failed, $actionRun->status);
        $this->assertSame('The action failed temporarily.', $actionRun->error_message);
        $this->assertSame(AutomationRunStatus::Failed, $actionRun->run->status);
        $this->assertSame('1 action failed.', $actionRun->run->failure_reason);
    }

    public function test_a_permanent_failure_is_not_retried(): void
    {
        Queue::fake();
        $this->automation([[AutomationActionType::CreateTask, ['title' => '']]]);
        $this->engine()->evaluate($this->classified());
        $actionRun = AutomationActionRun::sole();

        $this->engine()->executeActionRun($actionRun->id, finalAttempt: false); // no exception

        $this->assertSame(AutomationActionRunStatus::Failed, $actionRun->refresh()->status);
        $this->assertSame('A task title is required.', $actionRun->error_message);
    }

    public function test_the_job_records_a_reason_when_retries_are_exhausted(): void
    {
        Queue::fake();
        $this->automation([[AutomationActionType::CreateTask, ['title' => 'A']], [AutomationActionType::AddCustomerTag, ['tag' => 'vip']]]);
        $this->engine()->evaluate($this->classified());
        [$first, $second] = AutomationActionRun::orderBy('id')->get();

        $job = new ExecuteAutomationActionJob($first->id);
        $this->assertSame(3, $job->tries);
        $job->failed(new RuntimeException('worker timeout'));

        $this->assertSame(AutomationActionRunStatus::Failed, $first->refresh()->status);
        $this->assertSame('The action could not be completed after retrying.', $first->error_message);
        Queue::assertPushed(ExecuteAutomationActionJob::class, fn ($job) => $job->actionRunId === $second->id);
    }

    // Loop protection and limits

    public function test_automation_chains_stop_at_the_maximum_depth(): void
    {
        // An action that raises a new "reply received" event every time it runs.
        $conversation = $this->conversation;
        $this->app->instance(CreateTaskAction::class, new class($conversation) implements AutomationActionInterface
        {
            private int $messageId = 1000;

            public function __construct(private Conversation $conversation) {}

            public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
            {
                event(new CustomerReplyReceived($this->conversation->organization_id, ++$this->messageId, $this->conversation->id, $this->conversation->customer_id));

                return AutomationActionResult::completed('Raised another event.');
            }
        });
        $this->automation(trigger: AutomationTriggerType::CustomerReplyReceived, withCondition: false);

        event(new CustomerReplyReceived($this->admin->organization_id, 1, $this->conversation->id, $this->customer->id));

        $runs = AutomationRun::orderBy('id')->get();
        $this->assertCount(11, $runs);
        $this->assertSame(range(0, 10), $runs->pluck('depth')->all());
        $this->assertTrue($runs->take(10)->every(fn ($run) => $run->status === AutomationRunStatus::Completed));
        $this->assertSame(AutomationRunStatus::Skipped, $runs->last()->status);
        $this->assertSame('Automation chain limit reached (depth 10).', $runs->last()->failure_reason);
        $this->assertSame(0, $runs->last()->actionRuns()->count());
    }

    public function test_extra_actions_beyond_the_per_run_limit_are_skipped(): void
    {
        config(['automation.limits.max_actions_per_run' => 2]);
        $this->automation([
            [AutomationActionType::CreateTask, ['title' => 'A']],
            [AutomationActionType::AddCustomerTag, ['tag' => 'one']],
            [AutomationActionType::AddCustomerTag, ['tag' => 'two']],
        ]);

        event($this->classified());

        $statuses = AutomationActionRun::orderBy('id')->get()->map(fn ($r) => $r->status->value)->all();
        $this->assertSame(['completed', 'completed', 'skipped'], $statuses);
        $this->assertSame('Action limit reached for this run.', AutomationActionRun::orderByDesc('id')->first()->result['message']);
        $this->assertSame(['one'], CustomerTag::pluck('slug')->all());
        $this->assertSame(AutomationRunStatus::Completed, AutomationRun::sole()->status);
    }

    public function test_only_the_configured_number_of_automations_run_per_event(): void
    {
        config(['automation.limits.max_automations_per_event' => 2]);
        $automations = [$this->automation(), $this->automation(), $this->automation()];

        event($this->classified());

        $this->assertSame([$automations[0]->id, $automations[1]->id], AutomationRun::orderBy('id')->pluck('automation_id')->all());
    }

    // Organization isolation and settings

    public function test_events_pointing_at_another_organizations_records_run_nothing(): void
    {
        $this->automation();
        $foreign = Conversation::factory()->create();
        $otherCustomer = Customer::factory()->for($this->admin->organization)->create();

        // Conversation from another tenant.
        event(new CustomerReplyClassified($this->admin->organization_id, 1, $foreign->id, $foreign->customer_id, 1, CustomerReplyIntent::ReadyToBook, 0.9));
        // Our conversation, but a customer it does not belong to.
        event(new CustomerReplyClassified($this->admin->organization_id, 2, $this->conversation->id, $otherCustomer->id, 2, CustomerReplyIntent::ReadyToBook, 0.9));
        // Another tenant's automation never sees our event.
        $foreignAutomation = Automation::factory()->active()->create(['organization_id' => $foreign->organization_id, 'trigger_type' => 'customer_reply_classified']);

        event($this->classified());

        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(0, $foreignAutomation->runs()->count());
        $this->assertSame(1, Task::count());
        $this->assertSame($this->admin->organization_id, Task::sole()->organization_id);
    }

    public function test_execution_rechecks_the_automation_and_organization_before_each_action(): void
    {
        Queue::fake();
        $automation = $this->automation([[AutomationActionType::CreateTask, ['title' => 'A']], [AutomationActionType::CreateTask, ['title' => 'B']]]);
        $this->engine()->evaluate($this->classified());
        [$first, $second] = AutomationActionRun::orderBy('id')->get();

        $automation->update(['status' => 'paused']);
        $this->engine()->executeActionRun($first->id);

        $this->assertSame(AutomationActionRunStatus::Skipped, $first->refresh()->status);
        $this->assertSame('The automation is no longer active.', $first->result['message']);

        // A tampered run that points at another organization's automation is refused.
        $foreign = Automation::factory()->active()->create();
        DB::table('automation_runs')->where('id', $first->automation_run_id)->update(['context' => json_encode(['organization_id' => $foreign->organization_id] + $first->run->context)]);
        $automation->update(['status' => 'active']);
        $this->engine()->executeActionRun($second->id);

        $this->assertSame(AutomationActionRunStatus::Failed, $second->refresh()->status);
        $this->assertSame('This action does not belong to the organization that triggered it.', $second->error_message);
        $this->assertSame(0, Task::count());
    }

    public function test_organization_settings_default_to_safe_values(): void
    {
        $organization = Organization::factory()->create()->refresh();

        $this->assertTrue($organization->automations_enabled);
        $this->assertFalse($organization->automatic_email_enabled);
        $this->assertTrue($organization->require_approval_for_email);
        $this->assertFalse($organization->allowsUnattendedAutomatedEmail());
    }

    public function test_disabling_automations_for_an_organization_stops_runs(): void
    {
        $this->automation();
        $this->organization()->forceFill(['automations_enabled' => false])->save();

        event($this->classified());

        $this->assertSame(0, AutomationRun::count());
        $this->assertSame(0, Task::count());
    }

    // Email safety

    private function emailAutomation(bool $requiresApproval = false): Automation
    {
        return $this->automation([[AutomationActionType::SendEmail, ['subject' => 'Booking your install', 'body' => 'Hi {customer_first_name}, we can book you in this week.'], $requiresApproval]]);
    }

    private function emailResult(): AutomationActionRun
    {
        return AutomationActionRun::where('action_type', 'send_email')->latest('id')->firstOrFail();
    }

    public function test_automatic_email_is_off_by_default(): void
    {
        Queue::fake([SendEmailJob::class]);
        EmailConnection::factory()->verified()->default()->create(['organization_id' => $this->admin->organization_id]);
        $this->emailAutomation();

        event($this->classified());

        $this->assertSame(AutomationActionRunStatus::Skipped, $this->emailResult()->status);
        $this->assertSame('automatic_email_disabled', $this->emailResult()->result['data']['reason']);
        $this->assertSame(0, Message::count());
        Queue::assertNotPushed(SendEmailJob::class);
    }

    public function test_email_is_not_sent_when_approval_is_required(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        $this->organization()->forceFill(['require_approval_for_email' => true])->save();
        $organizationApproval = $this->emailAutomation();
        event($this->classified());

        // Organization approval off, but this action still requires approval.
        $organizationApproval->update(['status' => 'paused']);
        $this->organization()->forceFill(['require_approval_for_email' => false])->save();
        $this->emailAutomation(requiresApproval: true);
        event($this->classified());

        $reasons = AutomationActionRun::orderBy('id')->get()->map(fn ($r) => $r->result['data']['reason'])->all();
        $this->assertSame(['approval_required', 'approval_required'], $reasons);
        $this->assertSame(0, Message::count());
    }

    public function test_an_enabled_email_is_sent_once_through_the_email_service(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        $this->emailAutomation();
        $event = $this->classified();

        event($event);
        event($event);

        $message = Message::sole();
        $this->assertSame(AutomationActionRunStatus::Completed, $this->emailResult()->status);
        $this->assertSame($message->id, $this->emailResult()->result['data']['message_id']);
        $this->assertSame('sales@example.com', $message->from_address);
        $this->assertSame('john@example.com', $message->to_address);
        $this->assertSame($this->conversation->id, $message->conversation_id);
        $this->assertSame('Hi John, we can book you in this week.', $message->body_text);
        $this->assertSame('automation', $message->metadata['type']);
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_opted_out_customers_are_never_emailed(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        $this->customer->forceFill(['email_opted_out_at' => now()])->save();
        $this->emailAutomation();

        event($this->classified());

        $this->assertSame('opted_out', $this->emailResult()->result['data']['reason']);
        $this->assertSame(0, Message::count());
    }

    public function test_email_fails_without_a_verified_sender(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        EmailConnection::query()->update(['verification_status' => 'pending', 'verified_at' => null]);
        $this->emailAutomation();

        event($this->classified());

        $this->assertSame(AutomationActionRunStatus::Failed, $this->emailResult()->status);
        $this->assertSame('Your business email domain must be verified before emails can be sent.', $this->emailResult()->error_message);
        $this->assertSame(0, Message::count());
    }

    public function test_automated_emails_are_rate_limited_per_organization(): void
    {
        Queue::fake([SendEmailJob::class]);
        config(['automation.limits.max_automated_emails_per_hour' => 2]);
        $this->enableAutomaticEmail();
        $this->emailAutomation();

        foreach (range(1, 3) as $ignored) {
            event($this->classified());
        }

        $statuses = AutomationActionRun::orderBy('id')->get()->map(fn ($r) => $r->status->value)->all();
        $this->assertSame(['completed', 'completed', 'skipped'], $statuses);
        $this->assertSame('rate_limited', $this->emailResult()->result['data']['reason']);
        $this->assertSame(2, Message::count());
    }

    public function test_the_email_service_itself_refuses_opted_out_customers(): void
    {
        Queue::fake([SendEmailJob::class]);
        $this->enableAutomaticEmail();
        $this->customer->forceFill(['email_opted_out_at' => now()])->save();

        $this->expectException(EmailSendingNotAllowedException::class);
        $this->expectExceptionMessage('This customer has opted out of email.');

        app(EmailService::class)->sendToConversation($this->conversation, 'Hi', null, 'Hello');
    }
}

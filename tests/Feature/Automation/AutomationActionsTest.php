<?php

namespace Tests\Feature\Automation;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\SendEmailJob;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Automation\Actions\AddCustomerTagAction;
use App\Services\Automation\Actions\CancelFollowUpAction;
use App\Services\Automation\Actions\CompleteFollowUpAction;
use App\Services\Automation\Actions\CreateTaskAction;
use App\Services\Automation\Actions\NotifyUserAction;
use App\Services\Automation\Actions\RemoveCustomerTagAction;
use App\Services\Automation\Actions\ScheduleFollowUpAction;
use App\Services\Automation\Actions\SendEmailAction;
use App\Services\Automation\Actions\UpdateConversationStatusAction;
use App\Services\Automation\AutomationActionManager;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationActionsTest extends TestCase
{
    use RefreshDatabase;

    private AutomationActionManager $manager;

    private Conversation $conversation;

    private Customer $customer;

    private User $admin;

    private Automation $automation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(AutomationActionManager::class);
        $this->admin = User::factory()->admin()->create();
        $this->customer = Customer::factory()->for($this->admin->organization)->create(['name' => 'John Smith']);
        $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->admin->organization_id, 'subject' => 'AC Replacement']);
        $this->automation = Automation::factory()->active()->create(['organization_id' => $this->admin->organization_id]);
    }

    private function action(AutomationActionType|string $type, array $configuration = [], ?Automation $automation = null): AutomationAction
    {
        $automation ??= $this->automation;

        if (is_string($type)) {
            // A stored type the enum doesn't know, as a corrupted or outdated row would look.
            $id = DB::table('automation_actions')->insertGetId(['automation_id' => $automation->id, 'type' => $type, 'configuration' => json_encode($configuration), 'created_at' => now(), 'updated_at' => now()]);

            return (new AutomationAction)->setRawAttributes((array) DB::table('automation_actions')->find($id), sync: true);
        }

        return $automation->actions()->create(['type' => $type, 'configuration' => $configuration]);
    }

    private function context(string $eventId = 'classification:1', ?int $organizationId = null, ?int $customerId = null, ?int $conversationId = null): AutomationContext
    {
        return new AutomationContext(
            organizationId: $organizationId ?? $this->admin->organization_id,
            triggerType: AutomationTriggerType::CustomerReplyClassified,
            eventId: $eventId,
            customerId: $customerId ?? $this->customer->id,
            conversationId: $conversationId ?? $this->conversation->id,
            intent: CustomerReplyIntent::ReadyToBook,
            confidence: 0.97,
        );
    }

    // Manager

    public function test_each_action_type_resolves_to_its_handler(): void
    {
        $expected = [
            'create_task' => CreateTaskAction::class,
            'schedule_follow_up' => ScheduleFollowUpAction::class,
            'send_email' => SendEmailAction::class,
            'add_customer_tag' => AddCustomerTagAction::class,
            'update_conversation_status' => UpdateConversationStatusAction::class,
            'notify_user' => NotifyUserAction::class,
            'remove_customer_tag' => RemoveCustomerTagAction::class,
            'complete_follow_up' => CompleteFollowUpAction::class,
            'cancel_follow_up' => CancelFollowUpAction::class,
        ];

        foreach (AutomationActionType::cases() as $type) {
            $this->assertInstanceOf($expected[$type->value], $this->manager->handlerFor($type));
        }
    }

    public function test_unsupported_action_fails_safely(): void
    {
        $result = $this->manager->execute($this->action('issue_discount', ['percent' => 50]), $this->context());

        $this->assertFalse($result->success);
        $this->assertSame(AutomationActionRunStatus::Failed, $result->status);
        $this->assertSame('Unsupported action.', $result->message);
    }

    public function test_unexpected_handler_errors_become_failed_results(): void
    {
        $this->app->bind(CreateTaskAction::class, fn () => new class implements AutomationActionInterface
        {
            public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
            {
                throw new \RuntimeException('database exploded with secret details');
            }
        });

        $result = $this->manager->execute($this->action(AutomationActionType::CreateTask, ['title' => 'x']), $this->context());

        $this->assertSame(AutomationActionRunStatus::Failed, $result->status);
        $this->assertSame('The action failed unexpectedly.', $result->message);
    }

    public function test_result_is_normalized(): void
    {
        $this->assertSame(
            ['success' => true, 'status' => 'skipped', 'message' => 'Already done.', 'data' => ['reason' => 'already_exists']],
            AutomationActionResult::skipped('Already done.', ['reason' => 'already_exists'])->toArray(),
        );
    }

    // create_task

    public function test_create_task_creates_an_organization_scoped_task(): void
    {
        $this->travelTo(now()->startOfMinute());
        $action = $this->action(AutomationActionType::CreateTask, [
            'title' => 'Follow up with {customer_name} — customer is {intent}',
            'description' => 'About {conversation_subject}',
            'priority' => 'high',
            'due_in_hours' => 4,
            'assign_to' => $this->admin->id,
        ]);

        $result = $this->manager->execute($action, $this->context());

        $this->assertSame(AutomationActionRunStatus::Completed, $result->status);
        $task = Task::sole();
        $this->assertSame($result->data['task_id'], $task->id);
        $this->assertSame($this->admin->organization_id, $task->organization_id);
        $this->assertSame($this->customer->id, $task->customer_id);
        $this->assertSame($this->conversation->id, $task->conversation_id);
        $this->assertSame($this->admin->id, $task->assigned_to);
        $this->assertSame('Follow up with John Smith — customer is Ready to book', $task->title);
        $this->assertSame('About AC Replacement', $task->description);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertTrue($task->due_at->equalTo(now()->addHours(4)));
    }

    public function test_retrying_create_task_does_not_duplicate(): void
    {
        $action = $this->action(AutomationActionType::CreateTask, ['title' => 'Call {customer_name}']);

        $first = $this->manager->execute($action, $this->context());
        $retry = $this->manager->execute($action, $this->context());
        $otherEvent = $this->manager->execute($action, $this->context('classification:2'));

        $this->assertSame(AutomationActionRunStatus::Completed, $first->status);
        $this->assertSame(AutomationActionRunStatus::Skipped, $retry->status);
        $this->assertSame('already_exists', $retry->data['reason']);
        $this->assertSame($first->data['task_id'], $retry->data['task_id']);
        $this->assertSame(AutomationActionRunStatus::Completed, $otherEvent->status, 'A different event is a new task.');
        $this->assertSame(2, Task::count());
    }

    public function test_create_task_rejects_bad_configuration(): void
    {
        $outsider = User::factory()->admin()->create();

        foreach ([
            [['title' => ''], 'A task title is required.'],
            [['title' => 'x', 'priority' => 'urgent'], 'Invalid task priority.'],
            [['title' => 'x', 'due_in_hours' => -1], 'due_in_hours must be a whole number from 0 to 8760.'],
            [['title' => 'x', 'assign_to' => $outsider->id], 'The assigned user is not a member of this organization.'],
        ] as [$config, $message]) {
            $result = $this->manager->execute($this->action(AutomationActionType::CreateTask, $config), $this->context());
            $this->assertSame($message, $result->message);
            $this->assertFalse($result->success);
        }

        $this->assertSame(0, Task::count());
    }

    // add_customer_tag

    public function test_add_customer_tag_tags_the_customer(): void
    {
        $result = $this->manager->execute($this->action(AutomationActionType::AddCustomerTag, ['tag' => 'Ready to Book!']), $this->context());

        $this->assertSame(AutomationActionRunStatus::Completed, $result->status);
        $this->assertSame('ready-to-book', $result->data['tag']);
        $this->assertSame(['ready-to-book'], $this->customer->tags()->pluck('slug')->all());
        $this->assertSame($this->admin->organization_id, CustomerTag::sole()->organization_id);
    }

    public function test_duplicate_tag_is_safe(): void
    {
        $action = $this->action(AutomationActionType::AddCustomerTag, ['tag' => 'ready-to-book']);
        $this->manager->execute($action, $this->context());

        $again = $this->manager->execute($action, $this->context());
        $sameTagOtherAutomation = $this->manager->execute($this->action(AutomationActionType::AddCustomerTag, ['tag' => 'Ready To Book']), $this->context('classification:9'));

        $this->assertSame(AutomationActionRunStatus::Skipped, $again->status);
        $this->assertSame('already_exists', $again->data['reason']);
        $this->assertSame(AutomationActionRunStatus::Skipped, $sameTagOtherAutomation->status);
        $this->assertSame(1, CustomerTag::count());
        $this->assertSame(1, DB::table('customer_customer_tag')->count());
    }

    public function test_tags_are_separate_per_organization(): void
    {
        $other = Conversation::factory()->create();
        $otherAutomation = Automation::factory()->create(['organization_id' => $other->organization_id]);

        $this->manager->execute($this->action(AutomationActionType::AddCustomerTag, ['tag' => 'vip']), $this->context());
        $this->manager->execute(
            $this->action(AutomationActionType::AddCustomerTag, ['tag' => 'vip'], $otherAutomation),
            $this->context(organizationId: $other->organization_id, customerId: $other->customer_id, conversationId: $other->id),
        );

        $this->assertSame(2, CustomerTag::where('slug', 'vip')->count());
        $this->assertEqualsCanonicalizing([$this->admin->organization_id, $other->organization_id], CustomerTag::pluck('organization_id')->all());
    }

    public function test_invalid_tag_fails(): void
    {
        $result = $this->manager->execute($this->action(AutomationActionType::AddCustomerTag, ['tag' => '!!!']), $this->context());

        $this->assertSame('A valid tag is required.', $result->message);
        $this->assertSame(0, CustomerTag::count());
    }

    // update_conversation_status

    public function test_conversation_status_updates_to_each_allowed_value(): void
    {
        foreach ([ConversationStatus::WaitingBusiness, ConversationStatus::WaitingCustomer, ConversationStatus::Closed, ConversationStatus::Open] as $status) {
            $result = $this->manager->execute($this->action(AutomationActionType::UpdateConversationStatus, ['status' => $status->value]), $this->context());

            $this->assertSame(AutomationActionRunStatus::Completed, $result->status, $status->value);
            $this->assertSame($status, $this->conversation->fresh()->status);
        }
    }

    public function test_setting_the_same_status_is_skipped(): void
    {
        $result = $this->manager->execute($this->action(AutomationActionType::UpdateConversationStatus, ['status' => 'open']), $this->context());

        $this->assertSame(AutomationActionRunStatus::Skipped, $result->status);
        $this->assertSame('unchanged', $result->data['reason']);
    }

    public function test_invalid_conversation_status_is_rejected(): void
    {
        $result = $this->manager->execute($this->action(AutomationActionType::UpdateConversationStatus, ['status' => 'archived']), $this->context());

        $this->assertSame('Invalid conversation status.', $result->message);
        $this->assertSame(ConversationStatus::Open, $this->conversation->fresh()->status);
    }

    // notify_user

    public function test_notify_user_creates_in_app_notifications_for_admins(): void
    {
        $member = User::factory()->for($this->admin->organization)->create();
        $secondAdmin = User::factory()->manager()->for($this->admin->organization)->create();

        $result = $this->manager->execute($this->action(AutomationActionType::NotifyUser, ['message' => '{customer_name} is ready to book.']), $this->context());

        $this->assertSame(AutomationActionRunStatus::Completed, $result->status);
        $this->assertSame(2, $result->data['notified']);
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $secondAdmin->notifications()->count());
        $this->assertSame(0, $member->notifications()->count());

        $notification = $this->admin->notifications()->sole();
        $this->assertSame(AutomationNotification::class, $notification->type);
        $this->assertSame('John Smith is ready to book.', $notification->data['message']);
        $this->assertSame(route('inbox.show', $this->conversation->id), $notification->data['url']);
        $this->assertSame($this->conversation->id, $notification->data['conversation_id']);
    }

    public function test_notify_user_retry_does_not_duplicate(): void
    {
        $action = $this->action(AutomationActionType::NotifyUser, ['message' => 'Hi']);

        $this->manager->execute($action, $this->context());
        $retry = $this->manager->execute($action, $this->context());

        $this->assertSame(AutomationActionRunStatus::Skipped, $retry->status);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_notify_user_rejects_outside_recipients_and_other_channels(): void
    {
        $outsider = User::factory()->admin()->create();

        $wrongUser = $this->manager->execute($this->action(AutomationActionType::NotifyUser, ['message' => 'Hi', 'recipients' => $outsider->id]), $this->context());
        $sms = $this->manager->execute($this->action(AutomationActionType::NotifyUser, ['message' => 'Hi', 'channel' => 'sms']), $this->context());

        $this->assertSame('The recipient is not a member of this organization.', $wrongUser->message);
        $this->assertSame('Only in-app notifications are supported.', $sms->message);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    // Future actions

    public function test_send_email_is_off_by_default(): void
    {
        Http::fake();
        Queue::fake();

        $email = $this->manager->execute($this->action(AutomationActionType::SendEmail, ['subject' => 'Hi', 'body' => 'Hello']), $this->context());

        $this->assertSame(AutomationActionRunStatus::Skipped, $email->status);
        $this->assertSame('automatic_email_disabled', $email->data['reason']);

        Http::assertNothingSent();
        Queue::assertNotPushed(SendEmailJob::class);
        $this->assertSame(0, Message::count());
    }

    // Organization security

    public function test_an_action_cannot_run_for_another_organizations_event(): void
    {
        $other = Conversation::factory()->create();

        foreach ([
            $this->action(AutomationActionType::CreateTask, ['title' => 'x']),
            $this->action(AutomationActionType::AddCustomerTag, ['tag' => 'x']),
            $this->action(AutomationActionType::UpdateConversationStatus, ['status' => 'closed']),
            $this->action(AutomationActionType::NotifyUser, ['message' => 'x']),
        ] as $action) {
            $result = $this->manager->execute($action, $this->context(organizationId: $other->organization_id, customerId: $other->customer_id, conversationId: $other->id));

            $this->assertFalse($result->success, $action->type->value);
            $this->assertSame('This action does not belong to the organization that triggered it.', $result->message);
        }

        $this->assertSame(0, Task::count());
        $this->assertSame(0, CustomerTag::count());
        $this->assertSame(ConversationStatus::Open, $other->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_actions_reject_records_from_another_organization(): void
    {
        $foreign = Conversation::factory()->create();
        $context = $this->context(customerId: $foreign->customer_id, conversationId: $foreign->id);

        $task = $this->manager->execute($this->action(AutomationActionType::CreateTask, ['title' => 'x']), $context);
        $tag = $this->manager->execute($this->action(AutomationActionType::AddCustomerTag, ['tag' => 'x']), $context);
        $status = $this->manager->execute($this->action(AutomationActionType::UpdateConversationStatus, ['status' => 'closed']), $context);

        $this->assertSame('The customer or conversation does not belong to this organization.', $task->message);
        $this->assertSame('The customer does not belong to this organization.', $tag->message);
        $this->assertSame('The conversation does not belong to this organization.', $status->message);
        $this->assertSame(0, Task::count());
        $this->assertSame(0, DB::table('customer_customer_tag')->count());
        $this->assertSame(ConversationStatus::Open, $foreign->fresh()->status);
    }
}

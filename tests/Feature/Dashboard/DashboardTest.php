<?php

use App\Enums\AttentionPriority;
use App\Enums\ClassificationStatus;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\EstimateStatus;
use App\Enums\MessageStatus;
use App\Enums\ReplyUrgency;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\SendEmailJob;
use App\Livewire\Customers\CreateCustomerForm;
use App\Livewire\Dashboard;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Inbox\ConversationList;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\FollowUpNotification;
use App\Services\Dashboard\AttentionPriorityRules;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    // 15:00 UTC = 10:00 AM in Chicago (the default business timezone).
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    [$this->admin, $this->john, $this->johnConversation] = followUpBusiness('Dallas HVAC');
    $this->organization = $this->admin->organization;
});

/**
 * A classified customer reply: message, classification and the conversation fields the pipeline sets.
 */
function classifiedReply(Conversation $conversation, string $text, ?CustomerReplyIntent $intent, float $confidence = 0.9, array $classification = []): Message
{
    $message = customerReply($conversation, $text);
    $conversation->refresh()->recordActivity($message->received_at);

    if ($intent !== null) {
        $record = new MessageClassification;
        $record->forceFill($classification + [
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'request_id' => 'auto-'.$message->id,
            'status' => ClassificationStatus::Succeeded,
            'intent' => $intent,
            'confidence' => $confidence,
            'summary' => 'Summary',
            'requires_human_review' => false,
            'model' => 'test-model',
            'classified_at' => now(),
        ])->save();

        $conversation->forceFill(['latest_intent' => $intent, 'needs_attention' => $record->requires_human_review])->save();
    }

    return $message;
}

function customerWithConversation(Organization $organization, string $name): Conversation
{
    $customer = Customer::factory()->for($organization)->create(['name' => $name, 'email' => Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.com']);

    return Conversation::factory()->for($customer)->create(['organization_id' => $organization->id, 'subject' => 'Estimate', 'last_message_at' => now()]);
}

function reminderAt(User $user, Conversation $conversation, string $dueUtc, string $notes = 'Follow up on estimate'): FollowUp
{
    $followUp = app(FollowUpService::class)->scheduleManual($user, $conversation->customer, now()->addHour(), $notes, $conversation);
    $followUp->forceFill(['due_at' => CarbonImmutable::parse($dueUtc, 'UTC')])->save();

    return $followUp;
}

function taskFor(Conversation $conversation, string $title, ?string $dueUtc, TaskPriority $priority = TaskPriority::Medium, array $attributes = []): Task
{
    return Task::query()->forceCreate($attributes + [
        'organization_id' => $conversation->organization_id,
        'customer_id' => $conversation->customer_id,
        'conversation_id' => $conversation->id,
        'title' => $title,
        'priority' => $priority,
        'status' => TaskStatus::Pending,
        'due_at' => $dueUtc ? CarbonImmutable::parse($dueUtc, 'UTC') : null,
    ]);
}

function dashboard(User $user)
{
    return Livewire::withoutLazyLoading()->actingAs($user)->test(Dashboard::class);
}

function snapshot(User $user): DashboardSnapshot
{
    return app(DashboardService::class)->snapshot($user);
}

// Access

test('an authenticated user can access the dashboard', function () {
    $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('Loading your dashboard');

    dashboard($this->admin)->assertSee('Good morning, Mohsin')
        ->assertSee("Here's what's happening at ".$this->organization->name.' today.')
        ->assertSee('Sat, Oct 3, 2026');
});

test('a guest is redirected to sign in', function () {
    $this->get('/dashboard')->assertRedirect();
});

test('a user without an organization is blocked on the server', function () {
    $loner = User::factory()->create(['organization_id' => null]);

    $this->actingAs($loner)->get('/dashboard')->assertForbidden();
    Livewire::withoutLazyLoading()->actingAs($loner)->test(Dashboard::class)->assertForbidden();
});

// Organization isolation

test('organization A never sees organization B data', function () {
    [$otherAdmin, , $otherConversation] = followUpBusiness('Houston Plumbing');
    $otherConversation->customer->update(['name' => 'Foreign Fred']);
    classifiedReply($otherConversation, 'Book me in, foreign!', CustomerReplyIntent::ReadyToBook, 0.99);
    reminderAt($otherAdmin, $otherConversation, '2026-10-01 15:00:00', 'Foreign overdue');
    reminderAt($otherAdmin, $otherConversation, '2026-10-03 20:00:00', 'Foreign today');
    $otherRun = AutomationRun::query()->forceCreate(['organization_id' => $otherAdmin->organization_id, 'automation_id' => Automation::factory()->create(['organization_id' => $otherAdmin->organization_id, 'name' => 'Foreign automation'])->id,
        'event_type' => 'customer_reply_classified', 'event_id' => 'x:1', 'status' => 'completed']);
    $otherAdmin->notifyNow(new FollowUpNotification('Foreign notification', '/follow-ups', 1, 'due'));

    $snapshot = snapshot($this->admin);

    expect($snapshot->summary)->toBe(['overdue' => 0, 'due_today' => 0, 'waiting' => 0, 'new_replies' => 0])
        ->and($snapshot->attention)->toBeEmpty()
        ->and($snapshot->recentReplies)->toBeEmpty()
        ->and($snapshot->automationRuns)->toBeEmpty()
        ->and($snapshot->notifications)->toBeEmpty()
        ->and($snapshot->conversationStatuses['open'])->toBe(1);

    dashboard($this->admin)->assertDontSee('Foreign Fred')->assertDontSee('Foreign')->assertDontSee('Book me in');
    dashboard($otherAdmin)->assertSee('Foreign Fred')->assertSee('Foreign automation')->assertSee('Foreign notification');
});

test('counts and attention items are organization-specific', function () {
    [$otherAdmin, , $otherConversation] = followUpBusiness('Houston Plumbing');
    classifiedReply($otherConversation, 'Yes', CustomerReplyIntent::ReadyToBook);
    classifiedReply($this->johnConversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);

    expect(snapshot($this->admin)->summary['waiting'])->toBe(1)
        ->and(snapshot($this->admin)->attention->pluck('customerId')->all())->toBe([$this->john->id])
        ->and(snapshot($otherAdmin)->attention->pluck('customerId')->all())->toBe([$otherConversation->customer_id]);
});

// Summary cards

test('the overdue and due-today counts are correct', function () {
    reminderAt($this->admin, $this->johnConversation, '2026-10-02 15:00:00');         // yesterday: overdue
    reminderAt($this->admin, $this->johnConversation, '2026-10-01 15:00:00');         // overdue
    reminderAt($this->admin, $this->johnConversation, '2026-10-03 14:00:00');         // today, 9 AM: due today
    reminderAt($this->admin, $this->johnConversation, '2026-10-03 22:00:00');         // today, 5 PM: due today
    reminderAt($this->admin, $this->johnConversation, '2026-10-04 15:00:00');         // tomorrow
    $done = reminderAt($this->admin, $this->johnConversation, '2026-10-02 15:00:00'); // completed: not counted
    app(FollowUpService::class)->complete($done, $this->admin);

    $snapshot = snapshot($this->admin);

    expect($snapshot->summary['overdue'])->toBe(2)
        ->and($snapshot->summary['due_today'])->toBe(2)
        ->and($snapshot->today['follow_ups_completed'])->toBe(1)
        ->and($snapshot->today['follow_ups_due'])->toBe(2);
    dashboard($this->admin)->assertSeeHtml('data-count="overdue">2<')->assertSeeHtml('data-count="due_today">2<');
});

test('waiting-for-business counts only conversations that need the business', function () {
    $count = fn () => snapshot($this->admin)->summary['waiting'];

    classifiedReply($this->johnConversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook);
    classifiedReply(customerWithConversation($this->organization, 'Callback Cal'), 'Call me', CustomerReplyIntent::WantsCallback);
    classifiedReply(customerWithConversation($this->organization, 'Question Quinn'), 'How long?', CustomerReplyIntent::Question);
    classifiedReply(customerWithConversation($this->organization, 'Flagged Flo'), 'Hmm', CustomerReplyIntent::Unclear, 0.4, ['requires_human_review' => true]);
    customerWithConversation($this->organization, 'Marked Mo')->forceFill(['status' => ConversationStatus::WaitingBusiness])->save();
    expect($count())->toBe(5);

    // Not waiting for the business:
    classifiedReply(customerWithConversation($this->organization, 'No Thanks Nina'), 'No thanks', CustomerReplyIntent::NotInterested);
    classifiedReply(customerWithConversation($this->organization, 'Closed Carl'), 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::Closed])->save();
    classifiedReply(customerWithConversation($this->organization, 'Waiting Wendy'), 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::WaitingCustomer])->save();
    expect($count())->toBe(5);

    // Replying to the customer hands the turn back to them.
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    app(EmailService::class)->sendToConversation($this->johnConversation->fresh(), 'Booked!', null, 'See you Monday.');
    expect($this->johnConversation->fresh()->status)->toBe(ConversationStatus::WaitingCustomer)->and($count())->toBe(4);
});

test('the new-reply count covers the recent window only', function () {
    config(['dashboard.new_replies_hours' => 24]);
    $this->travel(-30)->hours();
    customerReply($this->johnConversation, 'Old reply');
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    customerReply($this->johnConversation, 'New reply');
    customerReply(customerWithConversation($this->organization, 'Sarah Wilson'), 'Another new one');

    expect(snapshot($this->admin)->summary['new_replies'])->toBe(2);
});

// Needs your attention

test('a ready-to-book customer appears with intent, confidence and a high priority', function () {
    classifiedReply($this->johnConversation, "Yes, I'd like to schedule this.", CustomerReplyIntent::ReadyToBook, 0.94);

    $item = snapshot($this->admin)->attention->sole();

    expect($item)
        ->customerName->toBe('John Smith')
        ->reason->toBe('Ready to book')
        ->excerpt->toBe("Yes, I'd like to schedule this.")
        ->confidence->toBe(0.94)
        ->priority->toBe(AttentionPriority::High)
        ->actionLabel->toBe('Open Conversation');

    dashboard($this->admin)->assertSeeInOrder(['Action required', 'John Smith', 'High priority', "“Yes, I'd like to schedule this.”", 'Ready to book', '94% confidence', 'Open Conversation']);
});

test('an overdue follow-up appears in the attention list', function () {
    $sarah = customerWithConversation($this->organization, 'Sarah Wilson');
    reminderAt($this->admin, $sarah, '2026-10-02 15:00:00', 'Follow up about estimate');

    $item = snapshot($this->admin)->attention->sole();

    expect($item->reason)->toBe('Follow-up overdue')
        ->and($item->priority)->toBe(AttentionPriority::High)
        ->and($item->url)->toContain('filter=overdue');
    dashboard($this->admin)->assertSeeInOrder(['Action required', 'Sarah Wilson', 'High priority', 'Follow up about estimate', 'Follow-up overdue', 'View Follow-Up']);
});

test('closed and waiting-for-customer conversations do not appear', function () {
    classifiedReply($this->johnConversation, 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::Closed])->save();
    classifiedReply(customerWithConversation($this->organization, 'Waiting Wendy'), 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::WaitingCustomer])->save();

    expect(snapshot($this->admin)->attention)->toBeEmpty();
    dashboard($this->admin)->assertDontSee('Customers to contact first');
});

test('with sending ready and nothing waiting, the action list says the business is all caught up', function () {
    $connection = EmailConnection::factory()->for($this->organization)->verified()->create();
    $connection->markAsDefault();

    expect(snapshot($this->admin)->alerts)->toBeEmpty();
    dashboard($this->admin)->assertSee("You're all caught up.")->assertSee('No customer action is currently required.');
});

test('a missing sending email shows as an action with a link only for people who may fix it', function () {
    expect(snapshot($this->admin)->alerts->pluck('key')->all())->toContain('email');

    dashboard($this->admin)->assertSee('Email sending needs attention')->assertSeeHtml('href="'.route('settings.email').'"');

    $staff = User::factory()->staff()->for($this->organization)->create();
    $alert = snapshot($staff)->alerts->firstWhere('key', 'email');
    expect($alert['url'])->toBeNull()->and($alert['action'])->toBeNull()
        ->and($alert['hint'])->toBe('Ask the business owner to connect and verify a sending email.');

    // Someone who cannot fix it is told who can, instead of seeing a problem with no way forward.
    dashboard($staff)->assertSee('Email sending needs attention')->assertSee('Ask the business owner to connect and verify a sending email.')->assertDontSee('Fix now');
    expect(snapshot($this->admin)->alerts->firstWhere('key', 'email')['hint'])->toBeNull();
});

test('attention is ordered by priority, and business rules can override the AI', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 14:00:00', 'UTC'));
    classifiedReply(customerWithConversation($this->organization, 'Question Quinn'), 'How long does it take?', CustomerReplyIntent::Question);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 14:10:00', 'UTC'));
    classifiedReply($this->johnConversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 14:20:00', 'UTC'));
    classifiedReply(customerWithConversation($this->organization, 'Unsure Ursula'), 'maybe book?', CustomerReplyIntent::ReadyToBook, 0.55);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));

    expect(snapshot($this->admin)->attention->map(fn ($i) => [$i->customerName, $i->priority->value])->all())->toBe([
        ['John Smith', 'high'],
        ['Unsure Ursula', 'medium'],   // low-confidence AI can't make it urgent; newer than Quinn
        ['Question Quinn', 'medium'],
    ]);

    $rules = app(AttentionPriorityRules::class);
    expect($rules->forConversation(ConversationStatus::WaitingBusiness, CustomerReplyIntent::NotInterested, 0.9, null, false))->toBe(AttentionPriority::Medium)
        ->and($rules->forConversation(ConversationStatus::Open, CustomerReplyIntent::Interested, 0.9, ReplyUrgency::High, false))->toBe(AttentionPriority::High)
        ->and($rules->forConversation(ConversationStatus::Open, CustomerReplyIntent::Unclear, 0.3, null, true))->toBe(AttentionPriority::Medium)
        ->and($rules->forConversation(ConversationStatus::Open, null, null, null, false))->toBe(AttentionPriority::Low);
});

// Follow-ups

test("today's follow-ups use the organization's timezone", function () {
    $this->organization->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    // 04:30 UTC Oct 4 = 9:30 PM Oct 3 in Los Angeles (today), but already Oct 4 in New York.
    reminderAt($this->admin, $this->johnConversation, '2026-10-04 04:30:00', 'Evening call');

    expect(snapshot($this->admin->fresh())->todaysFollowUps)->toHaveCount(1);
    dashboard($this->admin->fresh())->assertSeeInOrder(['Upcoming & overdue follow-ups', '9:30 PM', 'John Smith', 'Evening call', 'Open']);

    $this->organization->forceFill(['timezone' => 'America/New_York'])->save();
    expect(snapshot($this->admin->fresh())->todaysFollowUps)->toBeEmpty()
        ->and(snapshot($this->admin->fresh())->summary['due_today'])->toBe(0);
});

test('overdue follow-ups are shown separately from today\'s', function () {
    $sarah = customerWithConversation($this->organization, 'Sarah Wilson');
    reminderAt($this->admin, $sarah, '2026-10-02 15:00:00', 'Overdue call');
    reminderAt($this->admin, $this->johnConversation, '2026-10-03 16:30:00', 'Estimate follow-up');

    dashboard($this->admin)->assertSeeInOrder(['Upcoming & overdue follow-ups', 'View All Follow-Ups', 'Today (1)', 'Overdue (1)', '11:30 AM', 'John Smith', 'Estimate follow-up', 'Yesterday', 'Sarah Wilson', 'Overdue call']);
});

test('completed follow-ups do not appear as pending', function () {
    $done = reminderAt($this->admin, $this->johnConversation, '2026-10-03 16:00:00', 'Already handled');
    app(FollowUpService::class)->complete($done, $this->admin);

    $snapshot = snapshot($this->admin);

    expect($snapshot->todaysFollowUps)->toBeEmpty()->and($snapshot->overdueFollowUps)->toBeEmpty()->and($snapshot->summary['due_today'])->toBe(0);
    dashboard($this->admin)->assertDontSee('Already handled')->assertSee('No follow-ups are currently scheduled.');
});

// Activity

test('recent customer replies appear with their classification', function () {
    $sarah = customerWithConversation($this->organization, 'Sarah Wilson');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 14:00:00', 'UTC'));
    classifiedReply($sarah, 'Can you explain the pricing?', CustomerReplyIntent::Question, 0.88);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 14:58:00', 'UTC'));
    classifiedReply($this->johnConversation, "Yes, let's move forward.", CustomerReplyIntent::ReadyToBook, 0.94);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));

    dashboard($this->admin)->assertSeeInOrder([
        'Recent conversations',
        'John Smith', '2 minutes ago', "“Yes, let's move forward.”", 'Ready to book', '94%',
        'Sarah Wilson', '1 hour ago', '“Can you explain the pricing?”', 'Question', '88%',
    ])->assertSeeHtml('href="'.route('inbox.show', $this->johnConversation->id).'"');
});

test('automation activity appears', function () {
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'name' => 'Customer Ready to Book']);
    $run = AutomationRun::query()->forceCreate(['organization_id' => $this->organization->id, 'automation_id' => $automation->id, 'conversation_id' => $this->johnConversation->id, 'customer_id' => $this->johnConversation->customer_id,
        'event_type' => 'customer_reply_classified', 'event_id' => 'classification:1', 'status' => 'completed']);
    foreach (['Task created: Book John Smith', 'Customer tagged "ready-to-book".', 'Notified 1 person.'] as $message) {
        AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'create_task', 'status' => 'completed', 'result' => ['message' => $message]]);
    }

    dashboard($this->admin)->assertSeeInOrder(['Automation activity', 'View Automation Activity', '10:00 AM', 'Customer Ready to Book', 'John Smith', '✓', 'Task created: Book John Smith', 'Customer tagged', 'Notified 1 person.']);
});

test('unread notifications are shown and can be dismissed', function () {
    $this->admin->notifyNow(new FollowUpNotification("Sarah Wilson's follow-up is overdue.", route('follow-ups.index'), 1, 'overdue'));
    $this->travel(1)->minute();
    $this->admin->notifyNow(new FollowUpNotification("John Smith's follow-up is due today.", route('follow-ups.index'), 2, 'due'));
    $member = User::factory()->for($this->organization)->create();
    $member->notifyNow(new FollowUpNotification('For someone else', '/', 3, 'due'));

    $page = dashboard($this->admin)->assertSeeInOrder(['Notifications', '(2 unread)', "John Smith's follow-up is due today.", "Sarah Wilson's follow-up is overdue."])->assertDontSee('For someone else');

    $page->call('markNotificationRead', $this->admin->unreadNotifications()->latest()->first()->id)
        ->assertDontSee("John Smith's follow-up is due today.")->assertSee("Sarah Wilson's follow-up is overdue.");
    // Another user's notification can't be dismissed from here.
    $page->call('markNotificationRead', $member->notifications()->first()->id);
    expect($member->unreadNotifications()->count())->toBe(1);

    $page->call('markAllNotificationsRead')->assertSee('No new notifications.');
});

// Performance

// Tasks

test('an automation task due today appears in the attention list and the tasks section', function () {
    // The business already replied, so the conversation itself no longer needs attention; the task still does.
    $this->johnConversation->forceFill(['status' => ConversationStatus::WaitingCustomer])->save();
    taskFor($this->johnConversation, 'Book John Smith', '2026-10-03 19:00:00', TaskPriority::High, ['idempotency_key' => str_repeat('a', 64)]);

    $snapshot = snapshot($this->admin);
    $item = $snapshot->attention->sole();

    expect($item->kind)->toBe('task')
        ->and($item->priority)->toBe(AttentionPriority::High)
        ->and($item->reason)->toBe('Task: Book John Smith')
        ->and($item->overdue)->toBeFalse()
        ->and($item->url)->toBe(route('inbox.show', $this->johnConversation->id).'#tasks-heading')
        ->and($snapshot->tasks->pluck('title')->all())->toBe(['Book John Smith'])
        ->and($snapshot->taskCounts)->toBe(['open' => 1, 'due' => 1, 'overdue' => 0, 'later' => 0]);

    dashboard($this->admin)
        ->assertSeeInOrder(['Action required', 'John Smith', 'High priority', 'Task: Book John Smith', 'Open Task'])
        ->assertSeeInOrder(['Tasks (1)', 'Book John Smith', 'John Smith', 'High priority', 'due Oct 3, 2:00 PM', 'by automation', 'Complete']);
});

test('an overdue task is high priority whatever priority it was given', function () {
    taskFor($this->johnConversation, 'Call back about the quote', '2026-10-03 13:00:00', TaskPriority::Low);

    $item = snapshot($this->admin)->attention->sole();

    expect($item->priority)->toBe(AttentionPriority::High)
        ->and($item->reason)->toBe('Overdue task: Call back about the quote')
        ->and($item->overdue)->toBeTrue()
        ->and(snapshot($this->admin)->taskCounts['overdue'])->toBe(1);
    dashboard($this->admin)->assertSeeInOrder(['Call back about the quote', 'Overdue']);
});

test('an overdue task comes before a task that is not due yet at the same priority', function () {
    $sarah = customerWithConversation($this->organization, 'Sarah Wilson');
    taskFor($this->johnConversation, 'Book John Smith', '2026-10-03 19:00:00', TaskPriority::High);
    taskFor($sarah, 'Send financing options', '2026-10-03 13:00:00', TaskPriority::Low);

    expect(snapshot($this->admin)->attention->pluck('reason')->all())->toBe(['Overdue task: Send financing options', 'Task: Book John Smith']);
});

test('a task for a conversation already listed is not repeated in the attention list', function () {
    classifiedReply($this->johnConversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.97);
    taskFor($this->johnConversation, 'Book John Smith', '2026-10-03 19:00:00', TaskPriority::High);

    $snapshot = snapshot($this->admin);

    expect($snapshot->attention->pluck('kind')->all())->toBe(['conversation'])
        ->and($snapshot->tasks)->toHaveCount(1);
});

test('undated tasks are shown, later tasks are only counted, and finished tasks are hidden', function () {
    taskFor($this->johnConversation, 'Undated task', null, TaskPriority::Low);
    taskFor($this->johnConversation, 'Tomorrow task', '2026-10-04 16:00:00');
    taskFor($this->johnConversation, 'Done task', '2026-10-03 16:00:00', attributes: ['status' => TaskStatus::Completed, 'completed_at' => now()]);
    taskFor($this->johnConversation, 'Cancelled task', '2026-10-03 16:00:00', attributes: ['status' => TaskStatus::Cancelled]);

    $snapshot = snapshot($this->admin);

    expect($snapshot->tasks->pluck('title')->all())->toBe(['Undated task'])
        ->and($snapshot->attention->pluck('reason')->all())->toBe(['Task: Undated task'])
        ->and($snapshot->attention->sole()->priority)->toBe(AttentionPriority::Low)
        ->and($snapshot->taskCounts)->toBe(['open' => 2, 'due' => 1, 'overdue' => 0, 'later' => 1]);
    dashboard($this->admin)->assertSee('1 more task is scheduled for later.')->assertDontSee('Tomorrow task')->assertDontSee('Done task');
});

test('a task can be completed from the dashboard', function () {
    $task = taskFor($this->johnConversation, 'Book John Smith', '2026-10-03 19:00:00', TaskPriority::High);

    dashboard($this->admin)->call('completeTask', $task->id)->assertOk();

    expect($task->refresh()->status)->toBe(TaskStatus::Completed)
        ->and(snapshot($this->admin)->tasks)->toBeEmpty()
        ->and(snapshot($this->admin)->attention)->toBeEmpty();
    dashboard($this->admin)->assertSee('No tasks to do today.');
});

test("another organization's tasks are never shown and cannot be completed", function () {
    [$otherAdmin, , $otherConversation] = followUpBusiness('Houston Plumbing');
    $foreign = taskFor($otherConversation, 'Foreign task', '2026-10-03 19:00:00', TaskPriority::High);

    expect(snapshot($this->admin)->tasks)->toBeEmpty()
        ->and(snapshot($this->admin)->attention)->toBeEmpty()
        ->and(snapshot($otherAdmin)->tasks->pluck('title')->all())->toBe(['Foreign task']);
    dashboard($this->admin)->assertDontSee('Foreign task')->call('completeTask', $foreign->id)->assertNotFound();
    expect($foreign->refresh()->status)->toBe(TaskStatus::Pending);
});

test('task priority follows the task until it is overdue', function () {
    $rules = new AttentionPriorityRules;

    expect($rules->forTask(TaskPriority::High, false))->toBe(AttentionPriority::High)
        ->and($rules->forTask(TaskPriority::Medium, false))->toBe(AttentionPriority::Medium)
        ->and($rules->forTask(TaskPriority::Low, false))->toBe(AttentionPriority::Low)
        ->and($rules->forTask(TaskPriority::Low, true))->toBe(AttentionPriority::High);
});

/**
 * Seed $n customers, each with a classified reply, an overdue and a today follow-up, and an automation run.
 */
function seedVolume(object $test, int $n): void
{
    $automation = Automation::factory()->active()->create(['organization_id' => $test->organization->id]);

    foreach (range(1, $n) as $i) {
        $conversation = customerWithConversation($test->organization, "Customer {$i}");
        classifiedReply($conversation, "Reply {$i}", CustomerReplyIntent::ReadyToBook);
        reminderAt($test->admin, $conversation, '2026-10-02 15:00:00');
        reminderAt($test->admin, $conversation, '2026-10-03 20:00:00');
        $run = AutomationRun::query()->forceCreate(['organization_id' => $test->organization->id, 'automation_id' => $automation->id, 'conversation_id' => $conversation->id,
            'event_type' => 'customer_reply_classified', 'event_id' => "classification:{$i}", 'status' => 'completed']);
        AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'create_task', 'status' => 'completed', 'result' => ['message' => 'Task created']]);
        taskFor($conversation, "Task {$i}", '2026-10-03 20:00:00');
    }
}

function dashboardQueries(User $user): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    dashboard($user);
    // MySQL quotes identifiers with backticks, PostgreSQL with double quotes; the assertions use double quotes.
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => str_replace('`', '"', $sql))->all();
    DB::disableQueryLog();

    return $queries;
}

test('the dashboard has no N+1 queries: the query count does not grow with data', function () {
    seedVolume($this, 3);
    // The first render warms process-level caches (such as the plan catalog); measure after that.
    dashboardQueries($this->admin);
    $small = count(dashboardQueries($this->admin));

    seedVolume($this, 25);
    $large = count(dashboardQueries($this->admin));

    // The count must not depend on the amount of data. The ceiling is a budget for the sections the page has
    // (key numbers, alerts, follow-ups, conversations, plan usage, recent estimates, automation health).
    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(55);
});

test('the dashboard never loads whole customer, message or follow-up tables', function () {
    seedVolume($this, 15);

    $queries = collect(dashboardQueries($this->admin))
        ->filter(fn ($sql) => preg_match('/from "(customers|messages|follow_ups|conversations|automation_runs|message_classifications|tasks)"/', $sql));

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        // Eager loads fetch only the rows already selected (… "id" in (…)).
        $eagerLoad = (bool) preg_match('/"(id|conversation_id|message_id|automation_run_id)" in \(/', $sql);
        $bounded = $eagerLoad || str_contains($sql, 'count(') || str_contains($sql, ' limit ');

        expect($bounded)->toBeTrue("Unbounded query: {$sql}")
            ->and($eagerLoad || str_contains($sql, '"organization_id"'))->toBeTrue("Query not filtered by organization: {$sql}");
    }

    $snapshot = snapshot($this->admin);
    expect($snapshot->attention)->toHaveCount(config('dashboard.limits.attention'))
        ->and($snapshot->todaysFollowUps)->toHaveCount(config('dashboard.limits.follow_ups'))
        ->and($snapshot->tasks)->toHaveCount(config('dashboard.limits.tasks'))
        ->and($snapshot->recentReplies)->toHaveCount(config('dashboard.limits.replies'))
        ->and($snapshot->summary['overdue'])->toBe(15);
});

// Empty states and quick actions

test('a new business sees a clear first step instead of zeros', function () {
    $newAdmin = User::factory()->admin()->create();

    dashboard($newAdmin)
        ->assertSee('No customers yet.')
        ->assertSee('Add your first customer to get started.')
        ->assertDontSee('Overdue Follow-Ups')
        ->assertSeeLivewire('customers.create-customer-form');
});

test('quick actions: add a customer, create a quote, reply to a message and set a reminder', function () {
    dashboard($this->admin)
        ->assertSee(['Add a customer', 'Create a quote', 'Reply to a message', 'Set a reminder'])
        ->assertSeeHtml('href="'.route('estimates.create').'"')
        ->assertSeeHtml('href="'.route('inbox.index').'"')
        ->assertSeeHtml('href="'.route('follow-ups.index', ['schedule' => 1]).'"')
        ->set('showAddCustomer', true)
        ->assertSeeLivewire('customers.create-customer-form');

    Livewire::actingAs($this->admin)->test(CreateCustomerForm::class)
        ->set('first_name', 'Sarah')->set('last_name', 'Wilson')->set('email', 'not-an-email')->call('save')->assertHasErrors('email')
        ->set('email', ' JOHN@Example.com ')->call('save')->assertHasErrors('email')->assertSee('Customer already exists.')->assertSee('View Customer') // already a customer
        ->set('email', 'sarah@example.com')->call('save')->assertHasNoErrors()->assertRedirect();

    expect(Customer::where('email', 'sarah@example.com')->sole()->organization_id)->toBe($this->organization->id);

    // The follow-ups page opens the schedule form and honours the dashboard filters.
    Livewire::withQueryParams(['schedule' => 1])->actingAs($this->admin)->test(FollowUpIndex::class)->assertSet('showScheduleForm', true);
    reminderAt($this->admin, $this->johnConversation, '2026-10-02 15:00:00', 'Overdue thing');
    reminderAt($this->admin, $this->johnConversation, '2026-10-05 15:00:00', 'Later thing');
    Livewire::withQueryParams(['filter' => 'overdue'])->actingAs($this->admin)->test(FollowUpIndex::class)
        ->assertSee('Overdue thing')->assertDontSee('Later thing')->assertSee('Show all follow-ups');

    // Staff can view automations (read-only), so they see the link too.
    dashboard(User::factory()->staff()->for($this->organization)->create())->assertSeeHtml('href="'.route('automations.index').'"');
});

test('the inbox "Waiting for you" filter matches the dashboard card', function () {
    classifiedReply($this->johnConversation, 'Book it', CustomerReplyIntent::ReadyToBook);
    classifiedReply(customerWithConversation($this->organization, 'No Thanks Nina'), 'No thanks', CustomerReplyIntent::NotInterested);

    Livewire::actingAs($this->admin)->test(ConversationList::class)
        ->set('filter', 'waiting')->assertSee('John Smith')->assertDontSee('No Thanks Nina');
});

// End-to-end scenario

test('end to end: the dashboard shows what needs attention today', function () {
    // John Smith replies "Yes, let's schedule." → ready_to_book, 94%.
    classifiedReply($this->johnConversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);
    // Sarah Wilson: overdue follow-up. Mike Johnson: follow-up due today.
    reminderAt($this->admin, customerWithConversation($this->organization, 'Sarah Wilson'), '2026-10-02 15:00:00', 'Follow up about estimate');
    reminderAt($this->admin, customerWithConversation($this->organization, 'Mike Johnson'), '2026-10-03 19:00:00', 'Follow up on estimate');

    $summary = snapshot($this->admin)->summary;
    expect($summary['overdue'])->toBe(1)
        ->and($summary['due_today'])->toBe(1)
        ->and($summary['waiting'])->toBeGreaterThanOrEqual(1)
        ->and($summary['new_replies'])->toBeGreaterThanOrEqual(1);

    dashboard($this->admin)
        ->assertSeeHtml('data-count="overdue">1<')
        ->assertSeeHtml('data-count="due_today">1<')
        ->assertSeeInOrder([
            'Action required',
            'John Smith', 'Ready to book', '94% confidence', 'Open Conversation',
            'Sarah Wilson', 'Follow-up overdue', 'View Follow-Up',
            'Upcoming & overdue follow-ups', 'Mike Johnson', 'Open',
            'Recent conversations', 'John Smith', 'Ready to book', '94%',
        ]);
});

// Estimate pipeline, alerts, automation health, plan usage

function estimateFor(Customer $customer, EstimateStatus $status, string $total, array $attributes = []): Estimate
{
    // The estimates table requires the timestamp that matches each status.
    $defaults = match ($status) {
        EstimateStatus::Draft => [],
        EstimateStatus::Cancelled => ['cancelled_at' => now()->subDay()],
        EstimateStatus::Sent => ['sent_at' => now()->subDay()],
        EstimateStatus::Viewed => ['sent_at' => now()->subDay(), 'viewed_at' => now()->subHours(5)],
        EstimateStatus::Accepted => ['sent_at' => now()->subDays(3), 'accepted_at' => now()->subDay()],
        EstimateStatus::Declined => ['sent_at' => now()->subDays(3), 'declined_at' => now()->subDay()],
        EstimateStatus::Expired => ['sent_at' => now()->subDays(40), 'expired_at' => now()->subDay()],
    };

    return Estimate::factory()->create($attributes + $defaults + ['customer_id' => $customer->id, 'organization_id' => $customer->organization_id, 'status' => $status, 'total' => $total]);
}

test('the estimate pipeline counts each status and values only what is outstanding or recently accepted', function () {
    $john = $this->john;
    estimateFor($john, EstimateStatus::Draft, '100.00');
    estimateFor($john, EstimateStatus::Sent, '200.00', ['sent_at' => now()->subDay()]);
    estimateFor($john, EstimateStatus::Sent, '300.00', ['sent_at' => now()->subDays(2)]);
    estimateFor($john, EstimateStatus::Viewed, '400.00', ['sent_at' => now()->subDays(10)]);
    estimateFor($john, EstimateStatus::Accepted, '1000.00', ['sent_at' => now()->subDays(20), 'accepted_at' => now()->subDays(5)]);
    estimateFor($john, EstimateStatus::Accepted, '5000.00', ['sent_at' => now()->subDays(90), 'accepted_at' => now()->subDays(60)]);
    estimateFor($john, EstimateStatus::Declined, '700.00');
    estimateFor($john, EstimateStatus::Expired, '800.00');
    estimateFor($john, EstimateStatus::Cancelled, '9999.00');

    $pipeline = snapshot($this->admin)->estimatePipeline;

    expect($pipeline)->toMatchArray([
        'draft' => 1, 'sent' => 2, 'viewed' => 1, 'accepted' => 2, 'declined' => 1, 'expired' => 1,
        'outstanding_count' => 3, 'outstanding_cents' => 90000,
        'accepted_recent_count' => 1, 'accepted_recent_cents' => 100000,
    ]);
});

test('estimates sent in the last 7 days are compared with the 7 days before', function () {
    estimateFor($this->john, EstimateStatus::Sent, '10.00', ['sent_at' => now()->subDays(1)]);
    estimateFor($this->john, EstimateStatus::Sent, '10.00', ['sent_at' => now()->subDays(3)]);
    estimateFor($this->john, EstimateStatus::Viewed, '10.00', ['sent_at' => now()->subDays(10)]);

    expect(snapshot($this->admin)->estimatePipeline)->toMatchArray(['sent_7d' => 2, 'sent_prev_7d' => 1, 'sent_trend' => 100]);

    dashboard($this->admin)->assertSeeHtml('data-trend="100"');
});

test('no trend is shown when there is nothing to compare with', function () {
    estimateFor($this->john, EstimateStatus::Sent, '10.00', ['sent_at' => now()->subDay()]);

    expect(snapshot($this->admin)->estimatePipeline['sent_trend'])->toBeNull();
    dashboard($this->admin)->assertDontSeeHtml('data-trend=');
});

test('estimate figures never include another business or cancelled revisions', function () {
    [$otherAdmin, $otherCustomer] = followUpBusiness('Houston Plumbing');
    estimateFor($otherCustomer, EstimateStatus::Sent, '5000.00', ['sent_at' => now()]);
    estimateFor($otherCustomer, EstimateStatus::Accepted, '7000.00', ['accepted_at' => now()]);
    estimateFor($this->john, EstimateStatus::Cancelled, '300.00', ['sent_at' => now()]);

    expect(snapshot($this->admin)->estimatePipeline)->toMatchArray(['outstanding_count' => 0, 'outstanding_cents' => 0, 'accepted_recent_count' => 0, 'accepted_recent_cents' => 0, 'sent_7d' => 0]);
    expect(snapshot($otherAdmin)->estimatePipeline['outstanding_cents'])->toBe(500000);
});

test('the dashboard labels estimate values as totals, not collected revenue', function () {
    estimateFor($this->john, EstimateStatus::Sent, '900.00', ['sent_at' => now()]);
    estimateFor($this->john, EstimateStatus::Accepted, '1250.00', ['accepted_at' => now()->subDay()]);

    dashboard($this->admin)
        ->assertSeeHtml('data-count="awaiting">1<')
        ->assertSeeHtml('data-estimates="outstanding-value">$900.00<')
        ->assertSeeHtml('data-count="accepted_value">$1,250.00<')
        ->assertSee('They are not payments collected.')
        ->assertDontSee('Recovered revenue');
});

test('a business with no estimates sees zeros and a way to create one', function () {
    dashboard($this->admin)
        ->assertSeeHtml('data-count="accepted_value">$0.00<')
        ->assertSee('No estimates yet.')
        ->assertSeeHtml('href="'.route('estimates.create').'"');
});

test('key number cards open the list they count', function () {
    dashboard($this->admin)
        ->assertSeeHtml('data-card="awaiting"')
        ->assertSeeHtml('href="'.route('estimates.index', ['status' => 'awaiting']).'"')
        ->assertSeeHtml('href="'.route('follow-ups.index', ['filter' => 'today']).'"')
        ->assertSeeHtml('href="'.route('follow-ups.index', ['filter' => 'overdue']).'"')
        ->assertSeeHtml('href="'.route('estimates.index', ['status' => 'accepted']).'"')
        ->assertSeeHtml('data-card="new_replies"');
});

test('estimates about to expire are counted from the business calendar, sent or viewed only', function () {
    $today = $this->organization->localNow();
    estimateFor($this->john, EstimateStatus::Sent, '10.00', ['valid_until' => $today->addDays(2)->toDateString()]);
    estimateFor($this->john, EstimateStatus::Viewed, '10.00', ['valid_until' => $today->addDays(3)->toDateString()]);
    estimateFor($this->john, EstimateStatus::Sent, '10.00', ['valid_until' => $today->addDays(4)->toDateString()]);
    estimateFor($this->john, EstimateStatus::Accepted, '10.00', ['valid_until' => $today->toDateString()]);
    estimateFor($this->john, EstimateStatus::Draft, '10.00', ['valid_until' => $today->toDateString()]);

    expect(snapshot($this->admin)->alerts->firstWhere('key', 'expiring')['count'])->toBe(2);
});

test('failed emails are counted for the last 7 days and only for this business', function () {
    $mine = EmailConnection::factory()->for($this->organization)->verified()->create();
    Message::factory()->create(['email_connection_id' => $mine->id, 'status' => MessageStatus::Failed]);
    Message::factory()->create(['email_connection_id' => $mine->id, 'status' => MessageStatus::Failed, 'created_at' => now()->subDays(10)]);
    Message::factory()->create(['email_connection_id' => $mine->id, 'status' => MessageStatus::Sent]);
    Message::factory()->create(['status' => MessageStatus::Failed]); // another business's connection

    expect(snapshot($this->admin)->alerts->firstWhere('key', 'failed_emails')['count'])->toBe(1);
});

test('automation health comes from execution results and sending readiness, not from being enabled', function () {
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id]);
    Automation::factory()->paused()->create(['organization_id' => $this->organization->id]);

    // Active, but nothing can be sent.
    expect(snapshot($this->admin)->automationHealth)->toMatchArray(['active' => 1, 'paused' => 1, 'email_ready' => false, 'status' => 'email_not_ready']);

    EmailConnection::factory()->for($this->organization)->verified()->create()->markAsDefault();
    expect(snapshot($this->admin)->automationHealth['status'])->toBe('no_recent_runs');

    $run = fn (string $status, string $id) => AutomationRun::query()->forceCreate(['organization_id' => $this->organization->id, 'automation_id' => $automation->id,
        'conversation_id' => $this->johnConversation->id, 'event_type' => 'customer_reply_classified', 'event_id' => $id, 'status' => $status]);

    $run('completed', 'ok-1');
    expect(snapshot($this->admin)->automationHealth)->toMatchArray(['completed_7d' => 1, 'failed_7d' => 0, 'status' => 'healthy']);

    $run('failed', 'bad-1');
    expect(snapshot($this->admin)->automationHealth)->toMatchArray(['failed_7d' => 1, 'status' => 'failing']);

    dashboard($this->admin)->assertSeeHtml('data-automation-status="failing"')->assertSee('Needs attention');
});

test('a business with no automations is shown as not set up, never as healthy', function () {
    EmailConnection::factory()->for($this->organization)->verified()->create()->markAsDefault();

    expect(snapshot($this->admin)->automationHealth['status'])->toBe('idle');
    dashboard($this->admin)->assertSee('No automations are active yet.')->assertDontSee('running smoothly');
});

test('upcoming follow-ups are the next 7 days after today, with their total', function () {
    reminderAt($this->admin, $this->johnConversation, '2026-10-05 15:00:00', 'Next week call');
    reminderAt($this->admin, $this->johnConversation, '2026-10-12 15:00:00', 'Too far away');
    reminderAt($this->admin, $this->johnConversation, '2026-10-03 20:00:00', 'Later today');

    $snapshot = snapshot($this->admin);
    expect($snapshot->upcomingCount)->toBe(1)
        ->and($snapshot->upcomingFollowUps->pluck('notes')->all())->toBe(['Next week call']);

    dashboard($this->admin)->assertSee('Upcoming (1)')->assertSeeHtml('data-section="upcoming"');
});

test('plan usage shows the plan and counts only this business', function () {
    Customer::factory()->count(3)->create(['organization_id' => followUpBusiness('Houston Plumbing')[0]->organization_id]);

    $usage = snapshot($this->admin)->planUsage;
    $customers = collect($usage['rows'])->firstWhere('label', 'Customers');

    expect($usage['plan'])->not->toBeEmpty()
        ->and($usage['rows'])->toHaveCount(4)
        ->and($customers['used'])->toBe(1);

    dashboard($this->admin)->assertSeeHtml('data-plan>'.$usage['plan'].'<');
});

test('recent estimates lists the five newest with their status, without cancelled ones', function () {
    foreach (range(1, 6) as $i) {
        estimateFor($this->john, EstimateStatus::Sent, "{$i}00.00", ['estimate_number' => "EST-{$i}", 'created_at' => now()->subDays(10 - $i)]);
    }
    estimateFor($this->john, EstimateStatus::Cancelled, '50.00', ['estimate_number' => 'EST-CANCELLED', 'created_at' => now()]);

    $recent = snapshot($this->admin)->recentEstimates;
    expect($recent)->toHaveCount(5)->and($recent->pluck('estimate_number')->all())->toBe(['EST-6', 'EST-5', 'EST-4', 'EST-3', 'EST-2']);

    dashboard($this->admin)->assertSee('EST-6')->assertSee('$600.00')->assertDontSee('EST-CANCELLED')->assertDontSee('EST-1');
});

test('the follow-ups card opens on overdue when nothing is due today', function () {
    reminderAt($this->admin, $this->johnConversation, '2026-10-02 15:00:00', 'Overdue call');

    dashboard($this->admin)->assertSeeHtml("x-data=\"{ tab: 'overdue' }\"");
});

test('the notification bell shows the unread count and points at the notifications', function () {
    $this->admin->notifyNow(new FollowUpNotification('John Smith\'s follow-up is due today.', route('follow-ups.index'), 1, 'due'));

    dashboard($this->admin)->assertSeeHtml('data-bell-count>1<')->assertSeeHtml('href="#notifications"')->assertSeeHtml('id="notifications"');
});

test('the "Need reply" tab and tag follow the same rule as the waiting count', function () {
    // Open, but the customer's latest intent calls for action: waiting on the business.
    classifiedReply($this->johnConversation, 'Book it', CustomerReplyIntent::ReadyToBook);
    // Closed: never waiting, whatever the intent.
    classifiedReply(customerWithConversation($this->organization, 'Closed Carl'), 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::Closed])->save();

    expect($this->johnConversation->refresh()->isWaitingForBusiness())->toBeTrue()
        ->and(snapshot($this->admin)->summary['waiting'])->toBe(1);

    dashboard($this->admin)->assertSee('Need reply (1)')->assertSee('Needs reply');
});

test('team seats do not drive the overall plan usage, and the bar names what is closest to its limit', function () {
    $usage = snapshot($this->admin)->planUsage;
    $growing = collect($usage['rows'])->filter(fn ($row) => in_array($row['label'], ['Customers', 'Estimates', 'Automations'], true) && $row['limit']);
    $expected = (int) min(100, round($growing->map(fn ($row) => $row['used'] / $row['limit'])->max() * 100));

    expect($usage['percent'])->toBe($expected)
        ->and($usage['fullest'])->toBeIn(['Customers', 'Estimates', 'Automations']);

    dashboard($this->admin)->assertSeeHtml('data-plan-fullest>'.$usage['fullest'].'<')->assertSee('Closest to a limit:');
});

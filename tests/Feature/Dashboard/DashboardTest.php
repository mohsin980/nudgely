<?php

use App\Enums\AttentionPriority;
use App\Enums\ClassificationStatus;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\ReplyUrgency;
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
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Organization;
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
        ->assertSee("Here's what needs your attention today.")
        ->assertSee('Saturday, October 3, 2026');
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

    dashboard($this->admin)->assertSeeInOrder(['Needs your attention', 'John Smith', 'High priority', "“Yes, I'd like to schedule this.”", 'Ready to book', '94% confidence', 'Open Conversation']);
});

test('an overdue follow-up appears in the attention list', function () {
    $sarah = customerWithConversation($this->organization, 'Sarah Wilson');
    reminderAt($this->admin, $sarah, '2026-10-02 15:00:00', 'Follow up about estimate');

    $item = snapshot($this->admin)->attention->sole();

    expect($item->reason)->toBe('Follow-up overdue')
        ->and($item->priority)->toBe(AttentionPriority::High)
        ->and($item->url)->toContain('filter=overdue');
    dashboard($this->admin)->assertSeeInOrder(['Needs your attention', 'Sarah Wilson', 'High priority', 'Follow up about estimate', 'Follow-up overdue', 'View Follow-Up']);
});

test('closed and waiting-for-customer conversations do not appear', function () {
    classifiedReply($this->johnConversation, 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::Closed])->save();
    classifiedReply(customerWithConversation($this->organization, 'Waiting Wendy'), 'Book it', CustomerReplyIntent::ReadyToBook)->conversation->forceFill(['status' => ConversationStatus::WaitingCustomer])->save();

    expect(snapshot($this->admin)->attention)->toBeEmpty();
    dashboard($this->admin)->assertSee("You're all caught up.")->assertSee('No customer action is currently required.');
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
    dashboard($this->admin->fresh())->assertSeeInOrder(["Today's follow-ups", '9:30 PM', 'John Smith', 'Evening call', 'Open']);

    $this->organization->forceFill(['timezone' => 'America/New_York'])->save();
    expect(snapshot($this->admin->fresh())->todaysFollowUps)->toBeEmpty()
        ->and(snapshot($this->admin->fresh())->summary['due_today'])->toBe(0);
});

test('overdue follow-ups are shown separately from today\'s', function () {
    $sarah = customerWithConversation($this->organization, 'Sarah Wilson');
    reminderAt($this->admin, $sarah, '2026-10-02 15:00:00', 'Overdue call');
    reminderAt($this->admin, $this->johnConversation, '2026-10-03 16:30:00', 'Estimate follow-up');

    dashboard($this->admin)->assertSeeInOrder(["Today's follow-ups", 'View All Follow-Ups', 'Overdue (1)', 'Yesterday', 'Sarah Wilson', 'Overdue call', 'Today (1)', '11:30 AM', 'John Smith', 'Estimate follow-up']);
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
        'Recent customer replies',
        'John Smith', '2 minutes ago', "“Yes, let's move forward.”", 'Ready to book', '94%',
        'Sarah Wilson', '1 hour ago', '“Can you explain the pricing?”', 'Question', '88%',
    ])->assertSeeHtml('href="'.route('inbox.show', $this->johnConversation->id).'"');
});

test('automation activity appears', function () {
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'name' => 'Customer Ready to Book']);
    $run = AutomationRun::query()->forceCreate(['organization_id' => $this->organization->id, 'automation_id' => $automation->id, 'conversation_id' => $this->johnConversation->id,
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
    }
}

function dashboardQueries(User $user): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    dashboard($user);
    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    return $queries;
}

test('the dashboard has no N+1 queries: the query count does not grow with data', function () {
    seedVolume($this, 3);
    $small = count(dashboardQueries($this->admin));

    seedVolume($this, 25);
    $large = count(dashboardQueries($this->admin));

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(30);
});

test('the dashboard never loads whole customer, message or follow-up tables', function () {
    seedVolume($this, 15);

    $queries = collect(dashboardQueries($this->admin))
        ->filter(fn ($sql) => preg_match('/from "(customers|messages|follow_ups|conversations|automation_runs|message_classifications)"/', $sql));

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

test('quick actions: add a customer, schedule a follow-up, conversations and automations', function () {
    dashboard($this->admin)
        ->assertSee(['Add Customer', 'Schedule Follow-Up', 'View Conversations', 'View Automations'])
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

    // Members don't manage automations, so the link is hidden for them.
    dashboard(User::factory()->for($this->organization)->create())->assertDontSee('View Automations');
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
            'Needs your attention',
            'John Smith', 'Ready to book', '94% confidence', 'Open Conversation',
            'Sarah Wilson', 'Follow-up overdue', 'View Follow-Up',
            "Today's follow-ups", 'Mike Johnson', 'Open',
            'Recent customer replies', 'John Smith', 'Ready to book', '94%',
        ]);
});

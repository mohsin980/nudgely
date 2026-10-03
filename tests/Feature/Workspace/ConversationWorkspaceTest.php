<?php

use App\Enums\AttentionPriority;
use App\Enums\ClassificationStatus;
use App\Enums\ConversationCloseReason;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\ReplyUrgency;
use App\Enums\TaskStatus;
use App\Events\CustomerReplyClassified;
use App\Events\CustomerReplyReceived;
use App\Exceptions\Conversations\ConversationActionException;
use App\Jobs\EvaluateAutomationJob;
use App\Jobs\ExecuteAutomationActionJob;
use App\Jobs\SendEmailJob;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ShowConversation;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Conversations\ConversationService;
use App\Services\Dashboard\AttentionPriorityRules;
use App\Services\Dashboard\DashboardService;
use App\Services\Email\EmailService;
use App\Services\Email\ReplyRouteService;
use App\Services\FollowUps\FollowUpService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\PostmarkInboundPayloads;

uses(PostmarkInboundPayloads::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake([SendEmailJob::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 19:00:00', 'UTC')); // 2:00 PM in Chicago
    [$this->admin, $this->john, $this->conversation] = followUpBusiness('Dallas HVAC');
    $this->organization = $this->admin->organization;
    allowAutomaticFollowUpEmail($this->organization); // verified sales@example.com
});

/**
 * A customer reply classified by the AI, as the pipeline would record it.
 */
function aiReply(Conversation $conversation, string $text, CustomerReplyIntent $intent, float $confidence, ?ReplyUrgency $urgency = null): Message
{
    $message = customerReply($conversation, $text);
    $conversation->refresh()->recordActivity($message->received_at);
    $classification = new MessageClassification;
    $classification->forceFill([
        'organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
        'request_id' => 'auto-'.$message->id, 'status' => ClassificationStatus::Succeeded, 'intent' => $intent, 'confidence' => $confidence,
        'urgency' => $urgency, 'summary' => 'Customer appears ready to move forward.', 'requires_human_review' => false, 'model' => 'gpt-4.1-mini', 'classified_at' => now(),
    ])->save();
    $conversation->forceFill(['latest_intent' => $intent, 'latest_confidence' => $confidence, 'latest_urgency' => $urgency])->save();

    return $message;
}

function conversationPage(User $user, Conversation $conversation, array $params = [])
{
    return Livewire::withQueryParams($params)->actingAs($user)->test(ShowConversation::class, ['conversationId' => $conversation->id]);
}

function otherConversation(Organization $organization, string $name): Conversation
{
    $customer = Customer::factory()->for($organization)->create(['name' => $name, 'email' => Str::slug($name).'-'.Str::lower(Str::random(5)).'@example.com']);

    return Conversation::factory()->for($customer)->create(['organization_id' => $organization->id, 'subject' => 'Estimate', 'last_message_at' => now()->subHour()]);
}

// List

test('conversations are listed most recent first with unread count, last message, intent and follow-up', function () {
    $older = otherConversation($this->organization, 'Sarah Wilson');
    $older->forceFill(['last_message_at' => now()->subDay()])->save();
    $this->travel(1)->minute();
    aiReply($this->conversation, 'Great. What is the price?', CustomerReplyIntent::Question, 0.88);
    customerReply($this->conversation, 'Also, do you offer financing?');
    app(FollowUpService::class)->scheduleManual($this->admin, $this->john, CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'), 'Check in', $this->conversation);

    Livewire::actingAs($this->admin)->test(ConversationList::class)
        ->assertSeeInOrder(['John Smith', '2 unread', 'Your HVAC estimate', 'Customer: Also, do you offer financing?', 'Open', 'Question', '88%', 'Follow-up Oct 5', 'Sarah Wilson'])
        ->assertSeeHtml('data-unread="2"');
});

test('conversations can be filtered by status, priority, intent, follow-up and searched', function () {
    aiReply($this->conversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);
    $sarah = otherConversation($this->organization, 'Sarah Wilson');
    aiReply($sarah, 'How much?', CustomerReplyIntent::Question, 0.9);
    $mike = otherConversation($this->organization, 'Mike Johnson');
    $mike->forceFill(['status' => 'closed', 'latest_intent' => 'not_interested', 'latest_confidence' => 0.9])->save();
    $overdue = app(FollowUpService::class)->scheduleManual($this->admin, $sarah->customer, now()->addHour(), 'Overdue', $sarah);
    $overdue->forceFill(['due_at' => now()->subDays(2)])->save();

    $names = fn (array $f) => Livewire::withQueryParams($f)->actingAs($this->admin)->test(ConversationList::class)
        ->get('conversations')->map(fn ($c) => $c->customer->name)->sort()->values()->all();

    expect($names(['status' => 'closed']))->toBe(['Mike Johnson'])
        ->and($names(['priority' => 'high']))->toBe(['John Smith'])
        ->and($names(['priority' => 'medium']))->toBe(['Sarah Wilson'])
        ->and($names(['intent' => 'not_interested']))->toBe(['Mike Johnson'])
        ->and($names(['follow_up' => 'overdue']))->toBe(['Sarah Wilson'])
        ->and($names(['follow_up' => 'none']))->toBe(['John Smith', 'Mike Johnson'])
        ->and($names(['search' => 'wilson']))->toBe(['Sarah Wilson'])
        ->and($names(['search' => 'hvac estimate']))->toBe(['John Smith'])
        ->and($names(['filter' => 'waiting']))->toBe(['John Smith', 'Sarah Wilson']);
});

test('the SQL priority filter always agrees with the priority rules', function () {
    $rules = app(AttentionPriorityRules::class);
    $combos = 0;

    foreach (ConversationStatus::cases() as $status) {
        foreach ([null, ...CustomerReplyIntent::cases()] as $intent) {
            foreach ([null, 0.5, 0.95] as $confidence) {
                foreach ([null, ReplyUrgency::High] as $urgency) {
                    foreach ([false, true] as $needsAttention) {
                        $this->conversation->forceFill(['status' => $status, 'latest_intent' => $intent, 'latest_confidence' => $confidence, 'latest_urgency' => $urgency, 'needs_attention' => $needsAttention])->save();
                        [$sql, $bindings] = AttentionPriorityRules::conversationPrioritySql();
                        $fromSql = DB::table('conversations')->where('id', $this->conversation->id)->selectRaw("{$sql} as p", $bindings)->value('p');

                        expect($fromSql)->toBe($rules->forConversationModel($this->conversation->fresh())->value);
                        $combos++;
                    }
                }
            }
        }
    }

    expect($combos)->toBe(4 * 12 * 3 * 2 * 2);
});

test('the conversation list has no N+1 queries and pages in the database', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(ConversationList::class);

        return count(DB::getQueryLog());
    };

    foreach (range(1, 3) as $i) {
        aiReply(otherConversation($this->organization, "Small {$i}"), 'Hi', CustomerReplyIntent::Question, 0.9);
    }
    $small = $count();
    foreach (range(1, 30) as $i) {
        aiReply(otherConversation($this->organization, "Big {$i}"), 'Hi', CustomerReplyIntent::Question, 0.9);
    }

    expect($count())->toBe($small)
        ->and(Livewire::actingAs($this->admin)->test(ConversationList::class)->get('conversations')->count())->toBe(25);
});

test('the conversation list empty state', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test(ConversationList::class)->assertSee('No conversations yet.');
});

// Detail

test('the conversation page loads with customer, timeline, AI, composer, follow-up and actions', function () {
    aiReply($this->conversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);

    conversationPage($this->admin, $this->conversation)
        ->assertSeeInOrder(['John Smith', 'john@example.com', 'Customer Profile', 'Close Conversation',
            'Customer', "Yes, let's schedule.", 'AI Insight', 'Reply',
            'AI classification', 'Ready to book', '94% confidence', 'Classified Oct 3, 2:00 PM', 'Customer appears ready to move forward.',
            'Follow-up', 'Schedule Follow-Up', 'Tasks', 'Create Task']);
});

test('messages and events are shown chronologically with who did what', function () {
    $this->travel(-30)->minutes();
    customerReply($this->conversation, 'Hi, I need an AC installation.');
    $this->travel(5)->minutes();
    app(ConversationService::class)->reply($this->admin, $this->conversation, 'Re: AC', "We can help. I'll send an estimate.");
    $this->travel(5)->minutes();
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'name' => 'Customer Ready to Book']);
    $run = AutomationRun::query()->forceCreate(['organization_id' => $this->organization->id, 'automation_id' => $automation->id, 'conversation_id' => $this->conversation->id,
        'event_type' => 'customer_reply_classified', 'event_id' => 'c:1', 'status' => 'completed']);
    AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'create_task', 'status' => 'completed', 'result' => ['message' => 'Task created: Contact John Smith']]);
    $this->travel(5)->minutes();
    customerReply($this->conversation, 'Great. What is the price?');

    conversationPage($this->admin, $this->conversation)->assertSeeInOrder([
        'Customer', 'Hi, I need an AC installation.',
        'Business', "We can help. I'll send an estimate.",
        'Automation', 'Automation: Customer Ready to Book', 'Task created: Contact John Smith',
        'Customer', 'Great. What is the price?',
    ]);
});

test('long conversations load the latest messages first, with earlier ones on request', function () {
    foreach (range(1, 55) as $i) {
        $this->travel(1)->minute();
        customerReply($this->conversation, "Message number {$i}.");
    }

    $page = conversationPage($this->admin, $this->conversation)
        ->assertSee('Load earlier messages')->assertSee('Message number 55.')->assertDontSee('Message number 5.');

    $page->call('loadEarlierMessages')->assertSee('Message number 1.')->assertDontSee('Load earlier messages');
});

// Status, close, reopen

test('the conversation status can be changed and is recorded', function () {
    conversationPage($this->admin, $this->conversation)->call('setStatus', 'waiting_business')->assertSee('Status changed to Waiting on business.');

    expect($this->conversation->fresh()->status)->toBe(ConversationStatus::WaitingBusiness)
        ->and($this->conversation->events()->sole()->type)->toBe('status_changed');

    conversationPage($this->admin, $this->conversation)->call('setStatus', 'bogus')->assertStatus(422);
});

test('closing skips automated follow-ups, keeps messages and leaves the attention list; it can be reopened', function () {
    aiReply($this->conversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);
    $automated = automatedFollowUp($this->conversation);
    $manual = app(FollowUpService::class)->scheduleManual($this->admin, $this->john, now()->addDay(), 'Call', $this->conversation);
    expect(app(DashboardService::class)->snapshot($this->admin)->attention)->toHaveCount(1);

    conversationPage($this->admin, $this->conversation)
        ->call('setStatus', 'closed')->assertSet('showCloseForm', true)
        ->set('closeReason', 'completed')->set('closeNote', 'Job booked by phone.')
        ->call('closeConversation')
        ->assertSee('Conversation closed.')->assertSee('Reopen Conversation')->assertSee("Yes, let's schedule.");

    $conversation = $this->conversation->fresh();
    expect($conversation->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->closed_reason->value)->toBe('completed')
        ->and($conversation->messages()->count())->toBe(1)
        ->and($automated->fresh()->skip_reason)->toBe(FollowUpSkipReason::ConversationClosed)
        ->and($manual->fresh()->status)->toBe(FollowUpStatus::Pending)
        ->and(app(DashboardService::class)->snapshot($this->admin)->attention)->toBeEmpty();

    conversationPage($this->admin, $this->conversation)->call('reopenConversation')->assertSee('Conversation reopened.');
    expect($this->conversation->fresh())->status->toBe(ConversationStatus::Open)->closed_at->toBeNull();
});

test('a new customer reply to a closed conversation is kept and reopens it', function () {
    $this->configureInboundWebhook();
    $replyTo = app(ReplyRouteService::class)->createFor($this->conversation);
    app(ConversationService::class)->close($this->admin, $this->conversation, ConversationCloseReason::NoResponse);

    $this->postJson('/webhooks/email/inbound/postmark', $this->postmarkInbound($replyTo, ['From' => 'john@example.com', 'FromFull' => ['Email' => 'john@example.com'], 'TextBody' => 'Sorry for the delay, still interested!', 'StrippedTextReply' => 'Sorry for the delay, still interested!']), $this->webhookAuth())->assertOk();

    $conversation = $this->conversation->fresh();
    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->closed_at)->toBeNull()
        ->and(Conversation::count())->toBe(1)
        ->and($conversation->messages()->where('direction', 'inbound')->sole()->body_text)->toContain('still interested')
        ->and($conversation->events()->pluck('type')->all())->toBe(['closed', 'reopened']);
});

// Email

test('the business can reply by email through EmailService, from the verified sender', function () {
    $spy = Mockery::spy(app(EmailService::class));
    $this->app->instance(EmailService::class, $spy);

    conversationPage($this->admin, $this->conversation, ['compose' => 1])
        ->assertSet('composerOpen', true)
        ->assertSet('replySubject', 'Re: Your HVAC estimate')
        ->assertSeeInOrder(['To:', 'john@example.com'])
        ->set('replyBody', 'Your estimate is ready.')
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSee('Email sent to john@example.com.');

    $spy->shouldHaveReceived('sendToConversation')->once();
    $email = Message::where('direction', 'outbound')->sole();
    expect($email)
        ->from_address->toBe('sales@example.com')
        ->to_address->toBe('john@example.com')
        ->body_text->toBe('Your estimate is ready.')
        ->conversation_id->toBe($this->conversation->id)
        ->and($email->metadata['sent_by'])->toBe((string) $this->admin->id)
        ->and($this->conversation->fresh()->status)->toBe(ConversationStatus::WaitingCustomer);
    Queue::assertPushed(SendEmailJob::class, 1);
});

test('the composer validates the message', function () {
    conversationPage($this->admin, $this->conversation)->set('composerOpen', true)->set('replySubject', '')->set('replyBody', '')
        ->call('sendReply')->assertHasErrors(['replySubject', 'replyBody']);

    expect(Message::count())->toBe(0);
});

test('an unverified sender cannot send', function () {
    $this->organization->emailConnections()->update(['verification_status' => 'pending']);

    conversationPage($this->admin, $this->conversation)->set('composerOpen', true)->set('replyBody', 'Hello')
        ->call('sendReply')
        ->assertHasErrors('reply')
        ->assertSee('Unable to send email. Your business email domain must be verified before emails can be sent.');

    expect(Message::where('direction', 'outbound')->count())->toBe(0);
});

test('a failed email is recorded and shown safely, without provider details', function () {
    $message = app(ConversationService::class)->reply($this->admin, $this->conversation, 'Re: estimate', 'Hello');
    $message->forceFill(['status' => MessageStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'The email provider rejected the message.'])->save();

    conversationPage($this->admin, $this->conversation)
        ->assertSee('Unable to send email: The email provider rejected the message.')
        ->assertDontSee('local-server-token');

    // Opted-out customers are refused before anything is queued.
    $this->john->forceFill(['email_opted_out_at' => now()])->save();
    conversationPage($this->admin, $this->conversation)->set('composerOpen', true)->set('replyBody', 'Hi')
        ->call('sendReply')->assertSee('Unable to send email: this customer has opted out of email.');
});

// AI classification

test('the latest classification and its confidence appear', function () {
    aiReply($this->conversation, 'How much?', CustomerReplyIntent::Question, 0.71);
    $this->travel(1)->minute();
    aiReply($this->conversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);

    conversationPage($this->admin, $this->conversation)
        ->assertSeeInOrder(['AI classification', 'Ready to book', '94% confidence']);
});

test('an authorized user can correct the classification; the AI original stays in history', function () {
    Event::fake([CustomerReplyClassified::class]);
    aiReply($this->conversation, "I'll think about it.", CustomerReplyIntent::Interested, 0.81);
    $original = MessageClassification::sole();

    conversationPage($this->admin, $this->conversation)
        ->set('showOverrideForm', true)->set('overrideIntent', 'ready_to_book')->set('overrideReason', 'Called me to book.')
        ->call('overrideClassification')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['AI classification', 'Ready to book', 'Corrected by Mohsin (AI said Interested)', 'Reason: Called me to book.']);

    $override = MessageClassification::latest('id')->first();
    expect(MessageClassification::count())->toBe(2)
        ->and($original->fresh()->intent)->toBe(CustomerReplyIntent::Interested) // kept untouched
        ->and($override)
        ->source->toBe('manual')
        ->intent->toBe(CustomerReplyIntent::ReadyToBook)
        ->previous_intent->toBe(CustomerReplyIntent::Interested)
        ->overridden_by->toBe($this->admin->id)
        ->override_reason->toBe('Called me to book.')
        ->and($this->conversation->fresh()->latest_intent)->toBe(CustomerReplyIntent::ReadyToBook)
        ->and($this->conversation->events()->sole()->data)->toEqual(['from' => 'interested', 'to' => 'ready_to_book', 'reason' => 'Called me to book.']);

    // Correcting does not re-run automations.
    Event::assertNotDispatched(CustomerReplyClassified::class);

    conversationPage($this->admin, $this->conversation)->assertSee('Previous classifications (1)');
});

// Follow-ups

test('follow-ups appear in the conversation and can be scheduled, completed, rescheduled and cancelled there', function () {
    $page = conversationPage($this->admin, $this->conversation)
        ->assertSee('No follow-up scheduled.')
        ->call('openScheduleForm')->set('scheduleDate', '2026-10-05')->set('scheduleTime', '10:00')->set('scheduleNotes', 'Follow up about estimate.')
        ->call('scheduleFollowUp')->assertHasNoErrors();

    // A fresh render (after a call, Livewire's test HTML is JSON-escaped, so "—" wouldn't match).
    conversationPage($this->admin, $this->conversation)
        ->assertSeeInOrder(['Follow-up', 'Pending', 'Follow up about estimate.', 'Oct 5 — 10:00 AM', 'Complete', 'Reschedule', 'Cancel']);

    $followUp = FollowUp::sole();
    expect($followUp->conversation_id)->toBe($this->conversation->id);

    $page->call('openFollowUpForm', $followUp->id, 'reschedule')->set('rescheduleDate', '2026-10-06')->set('rescheduleTime', '14:00')->call('rescheduleFollowUp');
    expect($followUp->fresh()->due_at->toIso8601String())->toBe('2026-10-06T19:00:00+00:00');

    $page->call('openFollowUpForm', $followUp->id, 'complete')->call('completeFollowUp');
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Completed);

    $second = app(FollowUpService::class)->scheduleManual($this->admin, $this->john, now()->addDay(), 'Second', $this->conversation);
    $page->call('openFollowUpForm', $second->id, 'cancel')->set('cancelReason', 'duplicate')->call('cancelFollowUp');
    expect($second->fresh()->status)->toBe(FollowUpStatus::Cancelled);
});

// Automation

test('automation activity appears in the conversation', function () {
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'name' => 'Customer Ready to Book']);
    $run = AutomationRun::query()->forceCreate(['organization_id' => $this->organization->id, 'automation_id' => $automation->id, 'conversation_id' => $this->conversation->id,
        'event_type' => 'customer_reply_classified', 'event_id' => 'c:1', 'status' => 'completed']);
    foreach (['Task created: Book John Smith', 'Customer tagged "ready-to-book".', 'Notified 1 person.'] as $text) {
        AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'create_task', 'status' => 'completed', 'result' => ['message' => $text]]);
    }

    conversationPage($this->admin, $this->conversation)->assertSeeInOrder(['Automation', 'Automation: Customer Ready to Book', '✓', 'Task created: Book John Smith', '✓', 'Customer tagged', '✓', 'Notified 1 person.']);
});

test('the conversation UI never executes automations', function () {
    Queue::fake();
    Event::fake([CustomerReplyClassified::class, CustomerReplyReceived::class]);
    Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'trigger_type' => 'customer_reply_classified']);
    aiReply($this->conversation, 'Hello', CustomerReplyIntent::Interested, 0.8);

    conversationPage($this->admin, $this->conversation)
        ->set('replyBody', 'Thanks!')->call('sendReply')
        ->call('setStatus', 'waiting_business')
        ->set('overrideIntent', 'ready_to_book')->call('overrideClassification')
        ->set('taskTitle', 'Call back')->call('createTask');

    expect(AutomationRun::count())->toBe(0);
    Queue::assertNotPushed(EvaluateAutomationJob::class);
    Queue::assertNotPushed(ExecuteAutomationActionJob::class);
    Event::assertNotDispatched(CustomerReplyClassified::class);
});

test('tasks can be created and completed from the conversation', function () {
    conversationPage($this->admin, $this->conversation)
        ->set('showTaskForm', true)->set('taskTitle', 'Call John about the install date')->set('taskPriority', 'high')->call('createTask')
        ->assertSee('Call John about the install date');

    $task = Task::sole();
    expect($task)->conversation_id->toBe($this->conversation->id)->created_by->toBe($this->admin->id);

    conversationPage($this->admin, $this->conversation)->call('completeTask', $task->id);
    expect($task->fresh()->status)->toBe(TaskStatus::Completed);
});

// Unread

test('the unread count is correct and opening a conversation marks only its replies read', function () {
    customerReply($this->conversation, 'One');
    customerReply($this->conversation, 'Two');
    $sarah = otherConversation($this->organization, 'Sarah Wilson');
    customerReply($sarah, 'Unrelated');
    app(ConversationService::class)->reply($this->admin, $this->conversation, 'Re', 'Our reply'); // outbound: never "unread"

    $unread = fn (Conversation $c) => $c->messages()->where('direction', MessageDirection::Inbound)->whereNull('read_at')->count();
    expect($unread($this->conversation))->toBe(2)->and($unread($sarah))->toBe(1);
    Livewire::actingAs($this->admin)->test(ConversationList::class)->assertSeeHtml('data-unread="2"')->assertSeeHtml('data-unread="1"');

    conversationPage($this->admin, $this->conversation);

    expect($unread($this->conversation))->toBe(0)->and($unread($sarah))->toBe(1);
    Livewire::actingAs($this->admin)->test(ConversationList::class)->assertDontSeeHtml('data-unread="2"')->assertSeeHtml('data-unread="1"');
});

// Isolation & authorization

test('organization A cannot see organization B conversations or messages', function () {
    [$otherAdmin, , $foreign] = followUpBusiness('Houston Plumbing');
    $foreign->customer->update(['name' => 'Foreign Fred']);
    customerReply($foreign, 'Secret foreign message');

    Livewire::actingAs($this->admin)->test(ConversationList::class)->assertDontSee('Foreign Fred');
    Livewire::actingAs($this->admin)->test(ConversationList::class)->set('search', 'foreign')->assertSee('No matching conversations');
    $this->actingAs($this->admin)->get("/conversations/{$foreign->id}")->assertNotFound();
    expect($foreign->messages()->whereNull('read_at')->count())->toBe(1); // not marked read by a stranger
});

test('a user from another organization cannot send email or modify the conversation', function () {
    [$otherAdmin] = followUpBusiness('Houston Plumbing');
    $service = app(ConversationService::class);

    foreach ([
        fn () => $service->reply($otherAdmin, $this->conversation, 'Hi', 'Hello'),
        fn () => $service->setStatus($otherAdmin, $this->conversation, ConversationStatus::WaitingBusiness),
        fn () => $service->close($otherAdmin, $this->conversation),
        fn () => $service->overrideClassification($otherAdmin, $this->conversation, CustomerReplyIntent::Spam),
        fn () => app(TaskService::class)->create($otherAdmin, $this->john, 'X'),
    ] as $attempt) {
        expect($attempt)->toThrow(ConversationActionException::class);
    }

    expect(Message::count())->toBe(0)->and($this->conversation->fresh()->status)->toBe(ConversationStatus::Open)
        ->and($otherAdmin->can('update', $this->conversation))->toBeFalse()
        ->and($otherAdmin->can('update', $this->john))->toBeFalse()
        ->and($this->admin->can('update', $this->conversation))->toBeTrue();
});

test('users without an organization cannot open conversations', function () {
    $loner = User::factory()->create(['organization_id' => null]);

    $this->actingAs($loner)->get('/conversations')->assertForbidden();
    $this->actingAs($loner)->get("/conversations/{$this->conversation->id}")->assertForbidden();
});

// End-to-end

test('end to end: conversation, reply, AI classification, dashboard and automation', function () {
    // Customer John Smith (john@example.com) writes in; the business answers; the customer asks the price.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 19:31:00', 'UTC'));
    customerReply($this->conversation, 'Hi, I need an AC installation.');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 19:35:00', 'UTC'));
    app(ConversationService::class)->reply($this->admin, $this->conversation, 'Re: AC installation', "We can help. I'll send an estimate.");
    $this->travelTo(CarbonImmutable::parse('2026-10-03 19:40:00', 'UTC'));
    aiReply($this->conversation, 'Great. What is the price?', CustomerReplyIntent::Question, 0.86);

    // The business opens the conversation: customer, timeline, AI, composer, follow-up controls, timeline.
    $page = conversationPage($this->admin, $this->conversation)
        ->assertSeeInOrder(['John Smith', 'john@example.com', 'Customer Profile',
            'Hi, I need an AC installation.', "We can help. I'll send an estimate.", 'Great. What is the price?',
            'Reply', 'AI classification', 'Question', '86% confidence', 'Follow-up', 'Schedule Follow-Up']);

    // Business sends "Your estimate is ready."
    $page->set('composerOpen', true)->set('replyBody', 'Your estimate is ready.')->call('sendReply')->assertHasNoErrors();
    expect(Message::where('body_text', 'Your estimate is ready.')->sole()->from_address)->toBe('sales@example.com');

    // An active automation reacts to the next classified reply (run by the engine, not the UI).
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'name' => 'Customer Ready to Book', 'trigger_type' => 'customer_reply_classified']);
    $automation->actions()->create(['type' => 'create_task', 'configuration' => ['title' => 'Book {customer_name}', 'priority' => 'high']]);

    // Customer replies "Looks good. Let's schedule." → ready_to_book 94%.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 20:00:00', 'UTC'));
    $reply = aiReply($this->conversation, "Looks good. Let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);
    event(CustomerReplyClassified::fromClassification(MessageClassification::latest('id')->first(), $this->john->id));

    conversationPage($this->admin, $this->conversation)
        ->assertSeeInOrder(['Your estimate is ready.', "Looks good. Let's schedule.", 'Automation: Customer Ready to Book', 'Task created: Book John Smith',
            'AI classification', 'Ready to book', '94% confidence', 'Tasks', 'Book John Smith']);

    $snapshot = app(DashboardService::class)->snapshot($this->admin);
    $item = $snapshot->attention->first();
    expect($item->customerName)->toBe('John Smith')
        ->and($item->reason)->toBe('Ready to book')
        ->and($item->confidence)->toBe(0.94)
        ->and($item->priority)->toBe(AttentionPriority::High)
        ->and($snapshot->summary['waiting'])->toBe(1);
});

test('message times are shown in the business timezone, consistent with the rest of the timeline', function () {
    // 19:00 UTC = 2:00 PM in Chicago.
    customerReply($this->conversation, 'Hello there');

    conversationPage($this->admin, $this->conversation)->assertSee('Oct 3, 2026 2:00 PM')->assertDontSee('Oct 3, 2026 7:00 PM');
});

test('priority is only flagged while the business owes a reply', function () {
    aiReply($this->conversation, "Yes, let's schedule.", CustomerReplyIntent::ReadyToBook, 0.94);
    Livewire::actingAs($this->admin)->test(ConversationList::class)->assertSee('High priority');

    app(ConversationService::class)->reply($this->admin, $this->conversation, 'Re', 'Monday at 9?');
    Livewire::actingAs($this->admin)->test(ConversationList::class)->assertDontSee('High priority');
});

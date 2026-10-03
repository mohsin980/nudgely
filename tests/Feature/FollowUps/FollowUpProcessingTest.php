<?php

use App\Enums\ConversationStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Events\CustomerReplyReceived;
use App\Jobs\ProcessFollowUpJob;
use App\Jobs\SendEmailJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Notifications\FollowUpNotification;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    $this->processor = app(FollowUpProcessor::class);
    $this->organization = $this->admin->organization;
});

function outboundEmails(): int
{
    return Message::where('direction', 'outbound')->count();
}

// Scheduler

test('a pending follow-up becomes due and is queued once', function () {
    Queue::fake([ProcessFollowUpJob::class]);
    $followUp = automatedFollowUp($this->conversation);

    $this->travel(2)->days();
    $this->travel(1)->minute();
    $this->artisan('follow-ups:process-due')->expectsOutput('1 follow-up(s) marked due and queued.')->assertSuccessful();
    $this->artisan('follow-ups:process-due')->expectsOutput('0 follow-up(s) marked due and queued.');

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Due);
    Queue::assertPushed(ProcessFollowUpJob::class, 1);
    Queue::assertPushed(ProcessFollowUpJob::class, fn ($job) => $job->followUpId === $followUp->id);
});

test('a future follow-up remains pending', function () {
    Queue::fake([ProcessFollowUpJob::class]);
    $followUp = automatedFollowUp($this->conversation);

    $this->travel(1)->day();
    $this->artisan('follow-ups:process-due');

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Pending);
    Queue::assertNothingPushed();
});

test('the scheduler runs the follow-up commands', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression]);

    expect($events->first(fn ($e) => str_contains($e[0], 'follow-ups:process-due'))[1])->toBe('* * * * *')
        ->and($events->first(fn ($e) => str_contains($e[0], 'follow-ups:notify-overdue'))[1])->toBe('0 * * * *');
});

test('a due manual follow-up notifies the assignee once', function () {
    Notification::fake();
    $followUp = app(FollowUpService::class)->scheduleManual($this->admin, $this->customer, now()->addHour(), 'Call customer regarding estimate.');

    makeDue($followUp);
    $this->processor->process($followUp->id);

    expect($followUp->fresh())->status->toBe(FollowUpStatus::Due)->due_notified_at->not->toBeNull();
    Notification::assertSentTo($this->admin, FollowUpNotification::class, fn ($n) => $n->message === "John Smith's follow-up is due today.");
    Notification::assertSentTimes(FollowUpNotification::class, 1);
});

test('overdue follow-ups notify once', function () {
    $followUp = app(FollowUpService::class)->scheduleManual($this->admin, $this->customer, now()->addHour());
    makeDue($followUp);
    $this->processor->process($followUp->id);

    $this->travel(25)->hours();
    $this->artisan('follow-ups:notify-overdue')->expectsOutput('1 overdue follow-up notification(s) sent.');
    $this->artisan('follow-ups:notify-overdue')->expectsOutput('0 overdue follow-up notification(s) sent.');

    expect(DB::table('notifications')->pluck('data')->map(fn ($d) => json_decode($d)->message)->all())
        ->toBe(["John Smith's follow-up is due today.", "John Smith's follow-up is overdue."]);
});

// Customer reply

test('a customer reply skips the conversation\'s open automated follow-ups', function () {
    $followUp = automatedFollowUp($this->conversation);
    $manual = app(FollowUpService::class)->scheduleManual($this->admin, $this->customer, now()->addDay(), 'Call', $this->conversation);

    $this->travel(1)->day();
    $reply = customerReply($this->conversation, "Yes, I'm interested.");
    event(new CustomerReplyReceived($this->organization->id, $reply->id, $this->conversation->id, $this->customer->id));

    expect($followUp->fresh())
        ->status->toBe(FollowUpStatus::Skipped)
        ->skip_reason->toBe(FollowUpSkipReason::CustomerReplied)
        ->outcome->toBe('Follow-up skipped: Customer replied before the follow-up date.')
        ->and($manual->fresh()->status)->toBe(FollowUpStatus::Pending);
});

test('a historical completed follow-up is not cancelled by a reply', function () {
    $done = automatedFollowUp($this->conversation);
    app(FollowUpService::class)->complete($done, $this->admin, 'Customer approved estimate.');

    $this->travel(1)->day();
    $reply = customerReply($this->conversation);
    event(new CustomerReplyReceived($this->organization->id, $reply->id, $this->conversation->id, $this->customer->id));

    expect($done->fresh())->status->toBe(FollowUpStatus::Completed)->skip_reason->toBeNull();
});

test('a reply the event missed is still caught at due time', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $followUp = automatedFollowUp($this->conversation);

    $this->travel(1)->day();
    customerReply($this->conversation); // stored without the event
    makeDue($followUp);

    expect($followUp->fresh()->skip_reason)->toBe(FollowUpSkipReason::CustomerReplied)
        ->and(outboundEmails())->toBe(0);
});

// Automated email

test('email is not sent when automatic email is disabled', function () {
    Queue::fake([SendEmailJob::class]);
    Notification::fake();
    allowAutomaticFollowUpEmail($this->organization);
    $this->organization->forceFill(['automatic_email_enabled' => false])->save();
    $followUp = automatedFollowUp($this->conversation);

    makeDue($followUp);

    expect($followUp->fresh())->status->toBe(FollowUpStatus::Due)->due_notified_at->not->toBeNull()
        ->and(outboundEmails())->toBe(0);
    Notification::assertSentTo($this->admin, FollowUpNotification::class, fn ($n) => $n->message === "John Smith's follow-up is ready to send.");
});

test('email is not sent when approval is required, but can be sent by a person', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $this->organization->forceFill(['require_approval_for_email' => true])->save();
    $followUp = automatedFollowUp($this->conversation);

    makeDue($followUp);
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Due)->and(outboundEmails())->toBe(0);

    [$outcome, $message] = $this->processor->sendNow($followUp, $this->admin);

    expect($outcome)->toBe(FollowUpProcessor::SENT)
        ->and($message)->toBe('Follow-up email sent by Mohsin.')
        ->and($followUp->fresh())->status->toBe(FollowUpStatus::Completed)->completed_by->toBe($this->admin->id)
        ->and(outboundEmails())->toBe(1);
});

test('email is sent through EmailService when the automation is allowed', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $followUp = automatedFollowUp($this->conversation);

    $spy = Mockery::spy(app(EmailService::class));
    $this->app->instance(EmailService::class, $spy);
    $this->app->forgetInstance(FollowUpProcessor::class);

    makeDue($followUp);

    $spy->shouldHaveReceived('sendToConversation')->once();
    $email = Message::where('direction', 'outbound')->sole();
    expect($followUp->fresh())
        ->status->toBe(FollowUpStatus::Completed)
        ->completed_at->not->toBeNull()
        ->message_id->toBe($email->id)
        ->and($email->from_address)->toBe('sales@example.com')
        ->and($email->subject)->toBe('Following up on your estimate')
        ->and($email->body_text)->toStartWith('Hi John,')
        ->and($email->body_text)->toEndWith("Best,\nDallas HVAC");
    Queue::assertPushed(SendEmailJob::class, fn ($job) => $job->messageId === $email->id);
});

test('an opted-out customer does not receive email', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $followUp = automatedFollowUp($this->conversation);
    $this->customer->forceFill(['email_opted_out_at' => now()])->save();

    makeDue($followUp);

    expect($followUp->fresh()->skip_reason)->toBe(FollowUpSkipReason::OptedOut)->and(outboundEmails())->toBe(0);
});

// Idempotency

test('duplicate jobs cannot send duplicate emails', function () {
    Queue::fake([SendEmailJob::class, ProcessFollowUpJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp);

    $first = $this->processor->process($followUp->id);
    $second = $this->processor->process($followUp->id);
    (new ProcessFollowUpJob($followUp->id))->handle($this->processor);

    expect($first)->toBe(FollowUpProcessor::SENT)
        ->and($second)->toBe(FollowUpProcessor::ALREADY_PROCESSED)
        ->and(outboundEmails())->toBe(1);
    Queue::assertPushed(SendEmailJob::class, 1);
});

test('concurrent processing is safe', function () {
    Queue::fake([SendEmailJob::class, ProcessFollowUpJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $followUp = automatedFollowUp($this->conversation);
    $this->travelTo($followUp->due_at->addMinute());

    // Two scheduler runs racing: the atomic UPDATE … RETURNING hands the follow-up to only one.
    expect($this->processor->markDue() + $this->processor->markDue())->toBe(1);
    Queue::assertPushed(ProcessFollowUpJob::class, 1);

    // A worker that sent the email but died before saving the status: the unique automation_key
    // index stops a second email, and the retry completes the follow-up with the existing message.
    $key = hash('sha256', 'follow_up|'.$followUp->id);
    $earlier = app(EmailService::class)->sendToConversation($this->conversation, 'Following up', null, 'Hi', ['type' => 'follow_up', 'automation_key' => $key]);

    expect($this->processor->process($followUp->id))->toBe(FollowUpProcessor::ALREADY_PROCESSED)
        ->and($followUp->fresh())->status->toBe(FollowUpStatus::Completed)->message_id->toBe($earlier->id)
        ->and(outboundEmails())->toBe(1);

    // The row lock: processing happens inside SELECT … FOR UPDATE.
    DB::enableQueryLog();
    $this->processor->process($followUp->id);
    expect(collect(DB::getQueryLog())->pluck('query')->contains(fn ($sql) => str_contains($sql, 'for update')))->toBeTrue();
});

// Safety

test('a closed conversation skips the follow-up', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $followUp = automatedFollowUp($this->conversation);
    $this->conversation->forceFill(['status' => ConversationStatus::Closed])->save();

    makeDue($followUp);

    expect($followUp->fresh()->skip_reason)->toBe(FollowUpSkipReason::ConversationClosed)->and(outboundEmails())->toBe(0);
});

test('an inactive or deleted automation skips the follow-up', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $paused = automatedFollowUp($this->conversation);
    $paused->automation->update(['status' => 'paused']);
    $deleted = automatedFollowUp($this->conversation);
    $deleted->automation->delete();

    makeDue($paused);
    makeDue($deleted);

    expect($paused->fresh()->skip_reason)->toBe(FollowUpSkipReason::AutomationInactive)
        ->and($deleted->fresh()->skip_reason)->toBe(FollowUpSkipReason::AutomationDeleted)
        ->and(outboundEmails())->toBe(0);
});

test('another completed follow-up stops a later automated one', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $automated = automatedFollowUp($this->conversation);
    $manual = app(FollowUpService::class)->scheduleManual($this->admin, $this->customer, now()->addHour(), 'Call John');
    $this->travel(1)->minute();
    app(FollowUpService::class)->complete($manual, $this->admin, 'Spoke on the phone.');

    makeDue($automated);

    expect($automated->fresh()->skip_reason)->toBe(FollowUpSkipReason::AnotherFollowUpCompleted)->and(outboundEmails())->toBe(0);
});

test('rate limits are enforced and the follow-up waits for a person', function () {
    Queue::fake([SendEmailJob::class]);
    config(['follow_ups.limits.max_emails_per_hour' => 1]);
    allowAutomaticFollowUpEmail($this->organization);
    $customers = collect(range(1, 2))->map(function ($i) {
        $customer = Customer::factory()->for($this->organization)->create(['name' => "Customer {$i}"]);

        return Conversation::factory()->for($customer)->create(['organization_id' => $this->organization->id]);
    });
    $first = automatedFollowUp($customers[0]);
    $second = automatedFollowUp($customers[1]);

    makeDue($first);
    $this->processor->process($second->id);

    expect($first->fresh()->status)->toBe(FollowUpStatus::Completed)
        ->and($second->fresh())->status->toBe(FollowUpStatus::Due)->outcome->toContain('limit was reached')
        ->and(outboundEmails())->toBe(1);

    // The daily limit is configurable too.
    RateLimiter::clear(FollowUpProcessor::hourKey($this->organization->id));
    config(['follow_ups.limits.max_emails_per_day' => 1]);
    $third = automatedFollowUp($this->conversation, ['due_at' => now()->addMinutes(5)]);
    makeDue($third); // still within the same day
    expect($third->fresh()->status)->toBe(FollowUpStatus::Due)->and(outboundEmails())->toBe(1);
});

test('no more than one automated follow-up email per customer within the minimum interval', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $first = automatedFollowUp($this->conversation);
    makeDue($first);

    // Scheduled after the first was sent, and due two hours later.
    $second = automatedFollowUp($this->conversation, ['due_at' => now()->addHours(2)]);
    makeDue($second);

    expect($first->fresh()->status)->toBe(FollowUpStatus::Completed)
        ->and($second->fresh()->skip_reason)->toBe(FollowUpSkipReason::RecentlyFollowedUp)
        ->and(outboundEmails())->toBe(1);
});

test('an unverified sender fails the follow-up and tells the owner', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $this->organization->emailConnections()->update(['verification_status' => 'pending']);
    $followUp = automatedFollowUp($this->conversation);

    makeDue($followUp);

    expect($followUp->fresh())->status->toBe(FollowUpStatus::Failed)->outcome->toContain('must be verified')
        ->and(DB::table('notifications')->count())->toBe(1)
        ->and(outboundEmails())->toBe(0);
});

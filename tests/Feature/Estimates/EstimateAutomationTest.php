<?php

use App\Enums\Automation\AutomationTriggerType;
use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\MessageStatus;
use App\Enums\TaskPriority;
use App\Events\EstimateAccepted;
use App\Events\EstimateCreated;
use App\Events\EstimateDeclined;
use App\Events\EstimateExpired;
use App\Events\EstimateSent;
use App\Events\EstimateViewed;
use App\Jobs\EvaluateAutomationJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Task;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationTemplates;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PostmarkInboundPayloads;

uses(PostmarkInboundPayloads::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    [$this->admin, $this->john, $this->conversation] = estimateBusiness();
    $this->provider = fakeEmailProvider();
    $this->estimates = app(EstimateService::class);

    // An active automation built like a user would build it.
    $this->automation = function (string $trigger, array $actions, array $conditions = []): Automation {
        $builder = app(AutomationBuilder::class);
        $automation = $builder->save($this->admin->organization, $this->admin, ['name' => "On {$trigger}", 'trigger_type' => $trigger, 'conditions' => $conditions, 'actions' => $actions]);
        $builder->activate($automation, $this->admin);

        return $automation;
    };
});

// Events are available to Task 7 automation

test('estimate lifecycle events are automation triggers', function (string $event, AutomationTriggerType $trigger) {
    expect(AutomationBuilder::triggers())->toContain($trigger)
        ->and((new $event(1, 7, 3, 4))->triggerType())->toBe($trigger)
        ->and((new $event(1, 7, 3, 4))->eventId())->toBe('estimate:7');
})->with([
    'sent' => [EstimateSent::class, AutomationTriggerType::EstimateSent],
    'viewed' => [EstimateViewed::class, AutomationTriggerType::EstimateViewed],
    'accepted' => [EstimateAccepted::class, AutomationTriggerType::EstimateAccepted],
    'declined' => [EstimateDeclined::class, AutomationTriggerType::EstimateDeclined],
    'expired' => [EstimateExpired::class, AutomationTriggerType::EstimateExpired],
]);

test('each lifecycle change queues automation evaluation with the estimate in its context', function () {
    Queue::fake([EvaluateAutomationJob::class]);

    $estimate = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-10']);
    $this->estimates->recordView($estimate);
    $this->estimates->accept($estimate);
    $declined = sentEstimate($this->admin, $this->john);
    $this->estimates->decline($declined);
    $expiring = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'));
    $this->estimates->expireDue();

    $pushed = collect(Queue::pushed(EvaluateAutomationJob::class))->map(fn ($job) => [$job->event::class, $job->event->estimateId]);
    expect($pushed->all())->toEqualCanonicalizing([
        [EstimateCreated::class, $estimate->id], [EstimateCreated::class, $declined->id], [EstimateCreated::class, $expiring->id],
        [EstimateSent::class, $estimate->id], [EstimateViewed::class, $estimate->id], [EstimateAccepted::class, $estimate->id],
        [EstimateSent::class, $declined->id], [EstimateDeclined::class, $declined->id],
        [EstimateSent::class, $expiring->id], [EstimateExpired::class, $expiring->id],
    ]);
});

test('an "estimate declined" automation creates a high-priority task', function () {
    ($this->automation)('estimate_declined', [['type' => 'create_task', 'configuration' => ['title' => 'Review declined estimate for {customer_name}', 'priority' => 'high']]]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->estimates->decline($estimate);
    $this->estimates->decline($estimate); // a second decline triggers nothing

    $task = Task::sole();
    expect($task->title)->toBe('Review declined estimate for John Smith')
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->conversation_id)->toBe($estimate->conversation_id)
        ->and(AutomationRun::count())->toBe(1);
});

test('the estimate status condition is evaluated when the automation runs', function () {
    // "When viewed and still not accepted, create a task."
    ($this->automation)('estimate_viewed', [['type' => 'create_task', 'configuration' => ['title' => 'Call {customer_name} about the estimate']]],
        [['type' => 'estimate_status_equals', 'operator' => 'equals', 'value' => 'viewed']]);

    $viewed = sentEstimate($this->admin, $this->john);
    $this->estimates->recordView($viewed);
    expect(Task::count())->toBe(1);

    // Accepted before the queued evaluation ran: the condition no longer matches.
    Queue::fake([EvaluateAutomationJob::class]);
    $accepted = sentEstimate($this->admin, $this->john);
    $this->estimates->recordView($accepted);
    $this->estimates->accept($accepted);
    $job = collect(Queue::pushed(EvaluateAutomationJob::class))->first(fn ($j) => $j->event instanceof EstimateViewed);
    Queue::fake([]); // stop faking so the job can be handled directly
    app()->call([$job, 'handle']);

    expect(Task::count())->toBe(1);
});

test('estimate variables work in automated follow-up emails', function () {
    allowAutomaticFollowUpEmail($this->admin->organization);
    $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, 'estimate_follow_up');
    app(AutomationBuilder::class)->activate($automation, $this->admin);
    $estimate = sentEstimate($this->admin, $this->john);

    $followUp = FollowUp::sole();
    expect($followUp->estimate_id)->toBe($estimate->id)
        ->and($followUp->conversation_id)->toBe($estimate->conversation_id)
        ->and(now()->diffInDays($followUp->due_at))->toEqualWithDelta(3, 0.01);

    makeDue($followUp);

    expect($followUp->refresh()->status)->toBe(FollowUpStatus::Completed);
    $email = Message::where('metadata->type', 'follow_up')->sole();
    expect($email->subject)->toBe('Following up on estimate EST-1001')
        ->and($email->body_text)->toContain('estimate EST-1001 for AC Installation ($2,750.00)');
});

test('accepting or declining an estimate skips its automated follow-ups', function () {
    allowAutomaticFollowUpEmail($this->admin->organization);
    $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, 'estimate_follow_up');
    app(AutomationBuilder::class)->activate($automation, $this->admin);

    $accepted = sentEstimate($this->admin, $this->john);
    $this->estimates->accept($accepted);

    $followUp = FollowUp::where('estimate_id', $accepted->id)->sole();
    expect($followUp->status)->toBe(FollowUpStatus::Skipped)
        ->and($followUp->skip_reason)->toBe(FollowUpSkipReason::EstimateClosed);

    // A follow-up that slipped through is stopped again at due time.
    $declined = sentEstimate($this->admin, $this->john);
    $pending = FollowUp::where('estimate_id', $declined->id)->sole();
    Estimate::whereKey($declined->id)->update(['status' => 'declined', 'declined_at' => now()]);
    expect(app(FollowUpProcessor::class)->stopReason($pending->refresh()))->toBe(FollowUpSkipReason::EstimateClosed);
});

// End-to-end

test('end to end: draft → sent → viewed → accepted, and automation receives EstimateAccepted', function () {
    // John asks for an AC installation in a conversation.
    customerReply($this->conversation, 'Hi, can you quote an AC installation?');
    ($this->automation)('estimate_accepted', [
        ['type' => 'create_task', 'configuration' => ['title' => 'Schedule install for {customer_name}', 'priority' => 'high', 'due_in_hours' => 24]],
        ['type' => 'notify_user', 'configuration' => ['message' => '{customer_name} accepted an estimate.', 'recipients' => 'admins']],
    ]);
    // Estimate numbers continue from EST-1023.
    $this->admin->organization->forceFill(['estimate_sequence' => 1023])->save();
    Event::fake([EstimateViewed::class, EstimateAccepted::class]);

    // Draft
    $estimate = draftEstimate($this->admin, $this->john, ['conversation_id' => (string) $this->conversation->id, 'tax_rate' => '0']);
    expect($estimate->estimate_number)->toBe('EST-1024')
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->only(['subtotal', 'tax_amount', 'total']))->toBe(['subtotal' => '2750.00', 'tax_amount' => '0.00', 'total' => '2750.00']);

    // Send: queued through EmailService, delivered, sent.
    $message = $this->estimates->send($this->admin, $estimate);
    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Sent)
        ->and($estimate->sent_at)->not->toBeNull()
        ->and($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($this->provider->sent[0]->subject)->toBe('Estimate EST-1024 from Dallas HVAC');

    // The customer opens the secure link.
    $this->get($estimate->publicUrl())->assertOk()->assertSee('EST-1024')->assertSee('$2,750.00');
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Viewed);
    Event::assertDispatchedTimes(EstimateViewed::class, 1);

    // The customer accepts.
    $this->post($estimate->publicUrl().'/accept')->assertRedirect();
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Accepted)->and($estimate->accepted_at)->not->toBeNull();
    Event::assertDispatchedTimes(EstimateAccepted::class, 1);

    // The automation receives EstimateAccepted and runs its actions.
    $event = Event::dispatched(EstimateAccepted::class)[0][0];
    (new EvaluateAutomationJob($event))->handle(app(AutomationEngine::class));

    $task = Task::sole();
    expect($task->title)->toBe('Schedule install for John Smith')
        ->and($task->conversation_id)->toBe($this->conversation->id)
        ->and($this->admin->notifications()->count())->toBe(1)
        ->and(AutomationRun::sole()->event_type->value)->toBe('estimate_accepted');
});

test('end to end: a sent estimate expires when the scheduler runs after valid_until', function () {
    Event::fake([EstimateExpired::class]);
    $estimate = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    expect($estimate->status)->toBe(EstimateStatus::Sent);

    $this->travelTo(CarbonImmutable::parse('2026-10-04 15:00:00', 'UTC')); // the day after "valid until"
    $this->artisan('estimates:expire')->expectsOutput('1 estimate(s) expired.')->assertSuccessful();

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Expired);
    Event::assertDispatchedTimes(EstimateExpired::class, 1);
    Event::assertDispatched(EstimateExpired::class, fn ($e) => $e->estimateId === $estimate->id);
});

test('end to end: an "estimate sent" automation schedules a follow-up, and the customer\'s reply skips it', function () {
    $this->configureInboundWebhook();
    ($this->automation)('estimate_sent', [['type' => 'schedule_follow_up', 'configuration' => [
        'delay_days' => 3,
        'subject' => 'Following up on estimate {{estimate.number}}',
        'body' => "Hi {{customer.first_name}},\n\nAny questions about {{estimate.title}}?\n\n{{business.name}}",
    ]]]);

    $estimate = sentEstimate($this->admin, $this->john);
    $followUp = FollowUp::sole();
    expect($estimate->status)->toBe(EstimateStatus::Sent)
        ->and($followUp->status)->toBe(FollowUpStatus::Pending)
        ->and($followUp->estimate_id)->toBe($estimate->id)
        ->and($followUp->conversation_id)->toBe($estimate->conversation_id);

    // The customer replies to the estimate email (its Reply-To routes into the conversation).
    $this->travel(1)->day();
    $payload = $this->postmarkInbound($estimate->sendMessage->reply_to, [
        'From' => 'john@example.com', 'FromFull' => ['Email' => 'john@example.com', 'Name' => 'John Smith'],
        'TextBody' => 'Can you do Tuesday?', 'StrippedTextReply' => 'Can you do Tuesday?', 'HtmlBody' => '',
    ]);
    $this->postJson('/webhooks/email/inbound/postmark', $payload, $this->webhookAuth())->assertOk();

    expect($followUp->refresh()->status)->toBe(FollowUpStatus::Skipped)
        ->and($followUp->skip_reason)->toBe(FollowUpSkipReason::CustomerReplied)
        ->and(Message::where('conversation_id', $estimate->conversation_id)->where('direction', 'inbound')->count())->toBe(1);

    // Nothing is sent when the follow-up's date comes.
    $this->travel(3)->days();
    $this->artisan('follow-ups:process-due')->assertSuccessful();
    expect(Message::where('metadata->type', 'follow_up')->count())->toBe(0);
});

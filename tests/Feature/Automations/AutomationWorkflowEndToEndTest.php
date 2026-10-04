<?php

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\MessageDirection;
use App\Enums\TaskPriority;
use App\Livewire\Automations\AutomationForm;
use App\Livewire\Automations\AutomationLogs;
use App\Livewire\Automations\ShowAutomation;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Task;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use App\Services\Email\ReplyRouteService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\Fakes\FakeReplyClassifier;

uses(PostmarkInboundPayloads::class);

beforeEach(function () {
    $this->withoutVite();
    fakeEmailProvider();
    [$this->admin, $this->john] = estimateBusiness();
    allowAutomaticFollowUpEmail($this->admin->organization);
    $this->admin->organization->forceFill(['estimate_sequence' => 1023])->save();
});

/**
 * "Follow up after estimate" built in the step builder: Estimate sent → wait 3 days →
 * if the customer has not replied → email the customer and add a follow-up reminder.
 */
function buildFollowUpAfterEstimate(): Automation
{
    Livewire::actingAs(test()->admin)->test(AutomationForm::class)
        ->set('name', 'Follow up after estimate')->call('next')
        ->set('triggerType', 'estimate_sent')->call('next')
        ->set('wait', '4320')->call('addCondition')
        ->set('conditions.0', ['type' => 'customer_replied', 'operator' => 'is_false', 'value' => ''])->call('next')
        ->set('actions', [
            ['type' => 'send_email', 'requires_approval' => false, 'configuration' => [
                'recipient' => 'customer',
                'subject' => 'Checking in about your estimate',
                'body' => "Hi {{customer.first_name}},\n\nJust checking in regarding estimate {{estimate.number}}.\n\nPlease let us know if you have any questions.",
            ]],
            ['type' => 'schedule_follow_up', 'requires_approval' => false, 'configuration' => ['kind' => 'reminder', 'delay_days' => '2', 'title' => 'Call {{customer.name}} about {{estimate.number}}']],
        ])->call('next')->assertSet('step', 5)->assertHasNoErrors()
        ->call('saveDraft')->assertHasNoErrors();

    $automation = Automation::sole();
    expect($automation->status)->toBe(AutomationStatus::Draft);

    Livewire::actingAs(test()->admin)->test(ShowAutomation::class, ['automationId' => $automation->id])->call('activate')->assertHasNoErrors();
    expect($automation->refresh()->status)->toBe(AutomationStatus::Active);

    return $automation;
}

test('E2E 1: follow up after estimate emails the customer once after 3 days without a reply', function () {
    $automation = buildFollowUpAfterEstimate();

    $estimate = sentEstimate($this->admin, $this->john);
    expect($estimate->estimate_number)->toBe('EST-1024');

    // The execution exists right away, waiting for 3 days.
    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Waiting)
        ->and(abs($run->resume_at->diffInSeconds(now()->addDays(3))))->toBeLessThan(2)
        ->and(Message::where('subject', 'Checking in about your estimate')->exists())->toBeFalse();

    // Not yet due.
    $this->travel(2)->days();
    $this->artisan('automations:resume-waiting')->assertSuccessful();
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Waiting);

    // Three days later: the customer has not replied.
    $this->travel(1)->day();
    $this->travel(1)->minute();
    $this->artisan('automations:resume-waiting')->assertSuccessful();
    $this->artisan('automations:resume-waiting')->assertSuccessful(); // a second scheduler tick changes nothing

    $run->refresh();
    expect($run->status)->toBe(AutomationRunStatus::Completed)
        ->and($run->status->label())->toBe('Success')
        ->and($run->condition_results['results'][0]['passed'])->toBeTrue()
        ->and($run->actionRuns->every(fn ($r) => $r->status === AutomationActionRunStatus::Completed))->toBeTrue();

    $email = Message::where('direction', MessageDirection::Outbound)->where('subject', 'Checking in about your estimate')->sole();
    expect($email->from_address)->toBe('sales@example.com')
        ->and($email->to_address)->toBe('john@example.com')
        ->and($email->body_text)->toBe("Hi John,\n\nJust checking in regarding estimate EST-1024.\n\nPlease let us know if you have any questions.")
        ->and(FollowUp::where('automation_id', $automation->id)->count())->toBe(1)
        ->and(FollowUp::sole()->estimate_id)->toBe($estimate->id);

    Livewire::actingAs($this->admin)->test(AutomationLogs::class, ['automationId' => $automation->id, 'runId' => $run->id])
        ->assertSee('Success')->assertSee('John Smith')->assertSee('Email sent to the customer from sales@example.com.');
});

test('E2E 2: a customer reply during the wait skips the follow-up', function () {
    $automation = buildFollowUpAfterEstimate();
    $estimate = sentEstimate($this->admin, $this->john);

    $this->travel(1)->day();
    customerReply($estimate->conversation, 'Thanks, we are still deciding.');

    $this->travel(2)->days();
    $this->travel(1)->minute();
    $this->artisan('automations:resume-waiting')->assertSuccessful();

    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Skipped)
        ->and($run->failure_reason)->toBe('Customer already replied.')
        ->and($run->actionRuns)->toBeEmpty()
        ->and(Message::where('subject', 'Checking in about your estimate')->exists())->toBeFalse()
        ->and(FollowUp::count())->toBe(0);

    Livewire::actingAs($this->admin)->test(AutomationLogs::class, ['automationId' => $automation->id, 'runId' => $run->id])
        ->assertSee('Skipped')->assertSee('Customer already replied.');
});

test('E2E 3: ready to book alert creates one task and one notification', function () {
    config(['ai.classification.enabled' => true]);
    $this->configureInboundWebhook();
    $classifier = new FakeReplyClassifier;
    app(ReplyClassifierManager::class)->extend('openai', fn () => $classifier);

    $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, 'ready_to_book');
    expect($automation->status)->toBe(AutomationStatus::Draft);
    app(AutomationBuilder::class)->activate($automation, $this->admin);

    $estimate = sentEstimate($this->admin, $this->john);
    $classifier->willReturn(CustomerReplyIntent::ReadyToBook, 0.95);
    $payload = $this->postmarkInbound(app(ReplyRouteService::class)->createFor($estimate->conversation), [
        'MessageID' => 'reply-1', 'TextBody' => "Yes, let's schedule.", 'StrippedTextReply' => "Yes, let's schedule.",
    ]);

    $this->postJson('/webhooks/email/inbound/postmark', $payload, $this->webhookAuth())->assertOk();
    $this->postJson('/webhooks/email/inbound/postmark', $payload, $this->webhookAuth())->assertOk(); // provider retry

    $run = AutomationRun::where('automation_id', $automation->id)->sole();
    expect($run->status)->toBe(AutomationRunStatus::Completed)
        ->and($run->customer_id)->toBe($this->john->id)
        ->and(Message::where('direction', MessageDirection::Inbound)->sole()->body_text)->toContain("Yes, let's schedule.");

    $task = Task::sole();
    expect($task->title)->toBe('Book John Smith')
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and(DB::table('notifications')->where('notifiable_id', $this->admin->id)->count())->toBe(1);
});

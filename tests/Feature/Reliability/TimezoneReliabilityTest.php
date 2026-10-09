<?php

use App\Enums\CustomerReplyIntent;
use App\Events\CustomerReplyClassified;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationEngine;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Support\Facades\Queue;

// October 12, 2026 is still on daylight time in every US zone below.
dataset('business zones', [
    'UTC' => ['UTC', '09:00'],
    'America/New_York' => ['America/New_York', '13:00'],
    'America/Chicago' => ['America/Chicago', '14:00'],
    'America/Denver' => ['America/Denver', '15:00'],
    'America/Los_Angeles' => ['America/Los_Angeles', '16:00'],
]);

beforeEach(function () {
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    Queue::fake();
});

test('a 9:00 follow-up in the business timezone is stored as the matching UTC time', function (string $zone, string $utcHour) {
    $this->admin->organization->forceFill(['timezone' => $zone])->save();

    $due = app(FollowUpService::class)->parseLocal($this->admin->organization->fresh(), '2026-10-12', '09:00');

    expect($due->utc()->format('H:i'))->toBe($utcHour)
        ->and($due->utc()->format('Y-m-d'))->toBe('2026-10-12');
})->with('business zones');

test('a follow-up becomes due at the business-local time, not the server time', function (string $zone, string $utcHour) {
    $this->admin->organization->forceFill(['timezone' => $zone])->save();
    $due = app(FollowUpService::class)->parseLocal($this->admin->organization->fresh(), '2026-10-12', '09:00');
    $followUp = automatedFollowUp($this->conversation, ['due_at' => $due]);
    $processor = app(FollowUpProcessor::class);

    $this->travelTo($due->copy()->subMinute());
    expect($processor->markDue())->toBe(0);

    $this->travelTo($due->copy()->addMinute());
    expect($processor->markDue())->toBe(1)
        ->and($followUp->fresh()->due_at->equalTo($due))->toBeTrue();
})->with('business zones');

test('a three-day automation wait is the same instant in every timezone', function (string $zone) {
    $this->admin->organization->forceFill(['timezone' => $zone])->save();
    $automation = Automation::factory()->active()->create(['organization_id' => $this->admin->organization_id, 'trigger_type' => 'customer_reply_classified', 'wait_minutes' => 3 * 24 * 60]);
    $automation->conditions()->create(['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'ready_to_book']);
    $sentAt = now()->startOfSecond();
    $this->travelTo($sentAt);

    $event = new CustomerReplyClassified($this->admin->organization_id, 1, $this->conversation->id, $this->customer->id, 1, CustomerReplyIntent::ReadyToBook, 0.95);
    app(AutomationEngine::class)->evaluate($event);

    expect(AutomationRun::sole()->resume_at->equalTo($sentAt->copy()->addDays(3)))->toBeTrue();
})->with(['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles']);

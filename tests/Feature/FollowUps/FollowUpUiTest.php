<?php

use App\Enums\FollowUpCancelReason;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\BusinessSettings;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC')); // 10:00 AM in Chicago
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    $this->service = app(FollowUpService::class);
});

function reminder(object $test, string $dueUtc, string $notes = 'Follow up about estimate'): FollowUp
{
    $followUp = $test->service->scheduleManual($test->admin, $test->customer, now()->addHour(), $notes, $test->conversation);
    $followUp->forceFill(['due_at' => CarbonImmutable::parse($dueUtc, 'UTC')])->save();

    return $followUp;
}

// Completion

test('a user can complete a follow-up with notes', function () {
    $followUp = reminder($this, '2026-10-03 19:00:00');

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'complete')
        ->set('completionNotes', 'Customer approved estimate.')
        ->call('completeFollowUp')
        ->assertSet('followUpMessage', 'Follow-up completed.');

    expect($followUp->fresh())
        ->status->toBe(FollowUpStatus::Completed)
        ->completed_by->toBe($this->admin->id)
        ->completion_notes->toBe('Customer approved estimate.');
});

test('the completion timestamp is recorded', function () {
    $followUp = reminder($this, '2026-10-03 19:00:00');

    $this->service->complete($followUp, $this->admin);

    expect($followUp->fresh()->completed_at->toIso8601String())->toBe('2026-10-03T15:00:00+00:00')
        ->and(collect($followUp->fresh()->metadata['history'])->pluck('event')->all())->toBe(['scheduled', 'completed']);
});

// Rescheduling

test('a user can reschedule a due follow-up, keeping the same record', function () {
    $followUp = reminder($this, '2026-10-03 14:00:00');
    $followUp->forceFill(['status' => FollowUpStatus::Due, 'due_notified_at' => now()])->save();

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'reschedule')
        ->set('rescheduleDate', '2026-10-06')
        ->set('rescheduleTime', '14:00')
        ->call('rescheduleFollowUp')
        ->assertHasNoErrors()
        ->assertSet('followUpMessage', 'Follow-up rescheduled.');

    expect(FollowUp::count())->toBe(1)
        ->and($followUp->fresh())
        ->status->toBe(FollowUpStatus::Pending)
        ->due_notified_at->toBeNull();
});

test('the due time changes correctly, from the organization\'s local time to UTC', function () {
    $followUp = reminder($this, '2026-10-05 15:00:00');

    // 2:00 PM Central (CDT, UTC-5) on Oct 6 is 19:00 UTC.
    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'reschedule')
        ->assertSet('rescheduleDate', '2026-10-05')
        ->assertSet('rescheduleTime', '10:00')
        ->set('rescheduleDate', '2026-10-06')
        ->set('rescheduleTime', '14:00')
        ->call('rescheduleFollowUp');

    $history = collect($followUp->fresh()->metadata['history'])->firstWhere('event', 'rescheduled');
    expect($followUp->fresh()->due_at->toIso8601String())->toBe('2026-10-06T19:00:00+00:00')
        ->and($history['from'])->toBe('2026-10-05T15:00:00+00:00')
        ->and($history['to'])->toBe('2026-10-06T19:00:00+00:00');

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'reschedule')
        ->set('rescheduleDate', '2026-10-01')
        ->call('rescheduleFollowUp')
        ->assertHasErrors('followUp')
        ->assertSee('Choose a time in the future.');
});

// Cancellation

test('a user can cancel a follow-up and the reason is stored', function () {
    $followUp = reminder($this, '2026-10-05 15:00:00');

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'cancel')
        ->set('cancelReason', 'not_interested')
        ->set('cancelNote', 'Went with another contractor.')
        ->call('cancelFollowUp')
        ->assertSet('followUpMessage', 'Follow-up cancelled.');

    expect($followUp->fresh())
        ->status->toBe(FollowUpStatus::Cancelled)
        ->cancelled_reason->toBe(FollowUpCancelReason::NotInterested)
        ->cancelled_at->not->toBeNull()
        ->outcome->toBe('Went with another contractor.');
});

test('cancelling with "other" requires a note, and unknown reasons are rejected', function () {
    $followUp = reminder($this, '2026-10-05 15:00:00');

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'cancel')
        ->set('cancelReason', 'other')
        ->call('cancelFollowUp')->assertHasErrors('cancelNote')
        ->set('cancelReason', 'because_i_said_so')
        ->call('cancelFollowUp')->assertHasErrors('cancelReason');

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Pending);
});

// Creation from pages

test('follow-ups can be scheduled from the customer, conversation and follow-ups pages', function () {
    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('openScheduleForm')
        ->set('scheduleDate', '2026-10-05')
        ->set('scheduleTime', '10:00')
        ->set('scheduleNotes', 'Ask if customer is ready to proceed.')
        ->call('scheduleFollowUp')
        ->assertHasNoErrors();

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->call('openScheduleForm')->set('scheduleNotes', 'From the conversation')->call('scheduleFollowUp')->assertHasNoErrors();

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->call('openScheduleForm')->call('scheduleFollowUp')->assertHasErrors('schedule')
        ->set('scheduleCustomerId', (string) $this->customer->id)->set('scheduleNotes', 'From the list')->call('scheduleFollowUp')->assertHasNoErrors();

    $followUps = FollowUp::orderBy('id')->get();
    expect($followUps->pluck('notes')->all())->toBe(['Ask if customer is ready to proceed.', 'From the conversation', 'From the list'])
        ->and($followUps[0]->due_at->toIso8601String())->toBe('2026-10-05T15:00:00+00:00')
        ->and($followUps[0]->assigned_to)->toBe($this->admin->id)
        ->and($followUps[1]->conversation_id)->toBe($this->conversation->id)
        ->and($followUps->every(fn ($f) => $f->organization_id === $this->admin->organization_id))->toBeTrue();
});

// Security

test('organization isolation: other organizations\' follow-ups are invisible', function () {
    [$otherAdmin, $otherCustomer, $otherConversation] = followUpBusiness('Houston Plumbing');
    $otherCustomer->update(['name' => 'Foreign Fred']);
    app(FollowUpService::class)->scheduleManual($otherAdmin, $otherCustomer, now()->addHour(), 'Secret note');
    reminder($this, '2026-10-03 19:00:00', 'Our note');

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->assertSee('Our note')->assertDontSee('Secret note')->assertDontSee('Foreign Fred');

    $this->actingAs($this->admin)->get("/customers/{$otherCustomer->id}")->assertNotFound();
});

test('a user cannot modify another organization\'s follow-up', function () {
    [$otherAdmin, $otherCustomer] = followUpBusiness('Houston Plumbing');
    $foreign = app(FollowUpService::class)->scheduleManual($otherAdmin, $otherCustomer, now()->addHour());

    foreach (['complete', 'reschedule', 'cancel'] as $form) {
        Livewire::actingAs($this->admin)->test(FollowUpIndex::class)->call('openFollowUpForm', $foreign->id, $form)->assertNotFound();
    }
    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)->call('sendFollowUp', $foreign->id)->assertNotFound();

    // Even with a forged locked ID, the service refuses a user from another organization.
    expect(fn () => $this->service->complete($foreign, $this->admin))->toThrow(InvalidFollowUpException::class)
        ->and($foreign->fresh()->status)->toBe(FollowUpStatus::Pending)
        ->and($this->admin->can('update', $foreign))->toBeFalse()
        ->and($otherAdmin->can('update', $foreign))->toBeTrue();
});

test('members of the organization can work follow-ups; guests cannot', function () {
    $member = User::factory()->for($this->admin->organization)->create();
    $followUp = reminder($this, '2026-10-03 19:00:00');

    Livewire::actingAs($member)->test(FollowUpIndex::class)->call('openFollowUpForm', $followUp->id, 'complete')->call('completeFollowUp');
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Completed);

    auth()->logout();
    $this->get('/follow-ups')->assertRedirect();
});

// Timezone

test('follow-ups display in the organization\'s timezone', function () {
    reminder($this, '2026-10-05 17:00:00', 'Pacific reminder');

    // Default (no timezone set): US Central.
    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)->assertSee('Oct 5 — 12:00 PM')->assertSee('America/Chicago');

    $this->admin->organization->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    Livewire::actingAs($this->admin->fresh())->test(FollowUpIndex::class)->assertSee('Oct 5 — 10:00 AM')->assertDontSee('12:00 PM');

    $this->admin->organization->forceFill(['timezone' => 'America/New_York'])->save();
    Livewire::actingAs($this->admin->fresh())->test(FollowUpIndex::class)->assertSee('Oct 5 — 1:00 PM');
});

test('"today" follows the organization\'s timezone', function () {
    // 04:30 UTC on Oct 4 is still Oct 3 (9:30 PM) in Los Angeles, but already Oct 4 in New York.
    $this->admin->organization->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    reminder($this, '2026-10-04 04:30:00', 'Late evening call');

    Livewire::actingAs($this->admin->fresh())->test(FollowUpIndex::class)->assertSeeInOrder(['Due today', 'Late evening call', 'Upcoming']);

    $this->admin->organization->forceFill(['timezone' => 'America/New_York'])->save();
    Livewire::actingAs($this->admin->fresh())->test(FollowUpIndex::class)->assertSeeInOrder(['Upcoming', 'Late evening call', 'Completed']);
});

test('admins can set the business timezone; only US zones are accepted', function () {
    Livewire::actingAs($this->admin)->test(BusinessSettings::class)
        ->assertSet('timezone', 'America/Chicago')
        ->set('timezone', 'Asia/Karachi')->call('save')->assertHasErrors('timezone')
        ->set('timezone', 'America/Denver')->call('save')->assertHasNoErrors();

    expect($this->admin->organization->fresh()->timezone)->toBe('America/Denver');

    $this->actingAs(User::factory()->for($this->admin->organization)->create())->get('/settings/business')->assertForbidden();
});

// Pages

test('the follow-ups page groups overdue, due today and upcoming', function () {
    reminder($this, '2026-10-02 19:00:00', 'Follow up about estimate');
    reminder($this, '2026-10-03 19:00:00', 'Check if customer has questions');
    reminder($this, '2026-10-06 16:00:00', 'Follow up after estimate');
    $done = reminder($this, '2026-10-04 16:00:00', 'Initial estimate follow-up');
    $this->service->complete($done, $this->admin);

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->assertSeeInOrder([
            'Overdue', 'John Smith', 'Follow up about estimate', 'Yesterday — 2:00 PM', 'Complete', 'Reschedule', 'Cancel',
            'Due today', 'Check if customer has questions', 'Today — 2:00 PM',
            'Upcoming', 'Follow up after estimate', 'Oct 6 — 11:00 AM',
            'Completed', 'Initial estimate follow-up',
        ]);
});

test('the customer page shows upcoming follow-ups and history', function () {
    reminder($this, '2026-10-05 15:00:00', 'Follow up about estimate');
    $done = reminder($this, '2026-10-04 15:00:00', 'Initial estimate follow-up');
    $this->service->complete($done, $this->admin);

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->assertSeeInOrder(['John Smith', 'Schedule Follow-Up', 'Upcoming follow-ups', 'Pending', 'Follow up about estimate', 'Follow-up history', 'Initial estimate follow-up', 'Completed', 'Conversations', 'Your HVAC estimate']);
});

test('the conversation page shows the follow-up and a skipped one with its reason', function () {
    $followUp = automatedFollowUp($this->conversation);

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->assertSeeInOrder(['Follow-up', 'Pending', 'Interested Customer Follow-Up', 'Oct 5 — 10:00 AM', 'Complete', 'Reschedule', 'Cancel']);

    $this->service->skip($followUp->id, FollowUpSkipReason::CustomerReplied);

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->assertSeeInOrder(['Follow-up skipped', 'Skipped', 'Reason: Customer replied before the follow-up date.'])
        ->assertDontSee('Reschedule');
});

test('a ready follow-up offers Send Follow-Up', function () {
    $followUp = automatedFollowUp($this->conversation);
    $followUp->forceFill(['status' => FollowUpStatus::Due, 'due_notified_at' => now()])->save();

    Livewire::actingAs($this->admin)->test(FollowUpIndex::class)
        ->assertSeeInOrder(['Follow-up ready', 'Send Follow-Up', 'Reschedule', 'Cancel']);
});

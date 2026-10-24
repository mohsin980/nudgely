<?php

use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Events\EstimateViewed;
use App\Exceptions\Estimates\EstimateException;
use App\Livewire\Estimates\ShowEstimate;
use App\Models\ConversationEvent;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Services\Estimates\EstimateService;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    [$this->admin, $this->john, $this->conversation] = estimateBusiness();
    fakeEmailProvider();
    $this->estimates = app(EstimateService::class);
});

/** An automated follow-up scheduled for the estimate, still open. */
function openFollowUpFor(Estimate $estimate): FollowUp
{
    return FollowUp::factory()->automated()->create([
        'conversation_id' => $estimate->conversation_id,
        'estimate_id' => $estimate->id,
        'status' => FollowUpStatus::Pending,
    ]);
}

// Viewing

test('a link preview or email scanner that only loads the page does not mark the estimate viewed', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    $this->get($estimate->publicUrl())->assertOk();
    $this->get($estimate->publicUrl())->assertOk();

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent)->and($estimate->viewed_at)->toBeNull();
});

test('the browser report marks a sent estimate viewed, and only the first report counts', function () {
    Event::fake([EstimateViewed::class]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->post($estimate->publicUrl().'/viewed')->assertNoContent();
    $this->post($estimate->publicUrl().'/viewed')->assertNoContent();

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Viewed);
    Event::assertDispatchedTimes(EstimateViewed::class, 1);
});

test('a preview by someone from the business is not counted as the customer viewing it', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    $this->actingAs($this->admin)->post($estimate->publicUrl().'/viewed')->assertNoContent();

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent);
});

test('a draft or unknown link is not reported as viewed', function () {
    $draft = draftEstimate($this->admin, $this->john);

    // A draft has no customer link, so nothing can report it.
    $this->post('/estimate/view/'.str_repeat('a', 48).'/viewed')->assertNotFound();
    expect($draft->refresh()->status)->toBe(EstimateStatus::Draft);
});

// Outcomes recorded by the business

test('an acceptance made by phone is recorded, stops its follow-ups, and names who recorded it', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    $followUp = openFollowUpFor($estimate);

    $this->estimates->recordOutcome($this->admin, $estimate, EstimateStatus::Accepted);

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Accepted)->and($estimate->accepted_at)->not->toBeNull()
        ->and($followUp->refresh()->status)->toBe(FollowUpStatus::Skipped)
        ->and($followUp->skip_reason)->toBe(FollowUpSkipReason::EstimateClosed);

    $event = ConversationEvent::query()->where('estimate_id', $estimate->id)->where('type', 'estimate_accepted')->sole();
    expect($event->user_id)->toBe($this->admin->id);
});

test('a decline recorded by the business keeps a plain-text note', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    $this->estimates->recordOutcome($this->admin, $estimate, EstimateStatus::Declined, '<b>Too far</b> away');

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Declined)->and($estimate->decline_note)->toBe('Too far away');
});

test('an outcome can only be recorded for an estimate that is still waiting', function () {
    $draft = draftEstimate($this->admin, $this->john);

    expect(fn () => $this->estimates->recordOutcome($this->admin, $draft, EstimateStatus::Accepted))->toThrow(EstimateException::class);
    expect($draft->refresh()->status)->toBe(EstimateStatus::Draft);
});

test('another business cannot record an outcome on your estimate', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    [$stranger] = estimateBusiness('Other Plumbing');

    expect(fn () => $this->estimates->recordOutcome($stranger, $estimate, EstimateStatus::Accepted))->toThrow(EstimateException::class);
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent);
});

test('the estimate page records the outcome, with a confirmation message', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $estimate->id])
        ->set('outcomeNote', 'Went with someone closer')
        ->call('markDeclined')
        ->assertSet('statusMessage', 'Marked as declined. Automated follow-ups for this estimate have stopped.')
        ->assertSet('outcomeNote', '');

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Declined)->and($estimate->decline_note)->toBe('Went with someone closer');
});

<?php

use App\Enums\FollowUpCancelReason;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Database\Eloquent\MassAssignmentException;

beforeEach(function () {
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    $this->service = app(FollowUpService::class);
});

// Model

test('a follow-up belongs to an organization', function () {
    $followUp = FollowUp::factory()->create(['conversation_id' => $this->conversation->id]);

    expect($followUp->organization)->toBeInstanceOf(Organization::class)
        ->and($followUp->organization->id)->toBe($this->admin->organization_id)
        ->and($this->admin->organization->followUps()->pluck('id')->all())->toBe([$followUp->id]);
});

test('a follow-up belongs to a customer', function () {
    $followUp = FollowUp::factory()->create(['conversation_id' => $this->conversation->id]);

    expect($followUp->customer)->toBeInstanceOf(Customer::class)
        ->and($followUp->customer->id)->toBe($this->customer->id)
        ->and($this->customer->followUps()->count())->toBe(1);
});

test('a follow-up belongs to a conversation, which is optional', function () {
    $followUp = FollowUp::factory()->create(['conversation_id' => $this->conversation->id]);
    $reminder = $this->service->scheduleManual($this->admin, $this->customer, now()->addDay(), 'Call customer regarding estimate.');

    expect($followUp->conversation)->toBeInstanceOf(Conversation::class)
        ->and($followUp->conversation->id)->toBe($this->conversation->id)
        ->and($this->conversation->followUps()->count())->toBe(1)
        ->and($reminder->conversation)->toBeNull();
});

test('a follow-up can belong to an automation', function () {
    $followUp = automatedFollowUp($this->conversation);

    expect($followUp->automation)->toBeInstanceOf(Automation::class)
        ->and($followUp->automation->name)->toBe('Interested Customer Follow-Up')
        ->and($followUp->type)->toBe(FollowUpType::Automated);
});

// Creation

test('a manual follow-up can be created', function () {
    $member = User::factory()->for($this->admin->organization)->create();

    $followUp = $this->service->scheduleManual($this->admin, $this->customer, now()->addDays(2), 'Ask if customer is ready to proceed.', $this->conversation, $member);

    expect($followUp->fresh())
        ->type->toBe(FollowUpType::Manual)
        ->status->toBe(FollowUpStatus::Pending)
        ->organization_id->toBe($this->admin->organization_id)
        ->created_by->toBe($this->admin->id)
        ->assigned_to->toBe($member->id)
        ->notes->toBe('Ask if customer is ready to proceed.')
        ->automation_id->toBeNull()
        ->and($followUp->metadata['history'][0]['event'])->toBe('scheduled');
});

test('an automated follow-up can be created once per key', function () {
    $automation = Automation::factory()->active()->create(['organization_id' => $this->admin->organization_id, 'name' => 'Interested Customer']);

    [$first, $created] = $this->service->scheduleAutomated($automation, $this->conversation, now()->addDays(2), 'Subject', 'Body', 'key-1');
    [$again, $createdAgain] = $this->service->scheduleAutomated($automation, $this->conversation, now()->addDays(2), 'Subject', 'Body', 'key-1');

    expect($created)->toBeTrue()
        ->and($createdAgain)->toBeFalse()
        ->and($again->id)->toBe($first->id)
        ->and($first->fresh())
        ->type->toBe(FollowUpType::Automated)
        ->status->toBe(FollowUpStatus::Pending)
        ->automation_id->toBe($automation->id)
        ->customer_id->toBe($this->customer->id)
        ->and($first->reason())->toBe('Interested Customer')
        ->and(FollowUp::count())->toBe(1);
});

test('an invalid due date is rejected', function (Closure $dueAt, string $message) {
    expect(fn () => $this->service->scheduleManual($this->admin, $this->customer, $dueAt()))
        ->toThrow(InvalidFollowUpException::class, $message);

    expect(FollowUp::count())->toBe(0);
})->with([
    'in the past' => [fn () => now()->subHour(), 'Choose a time in the future.'],
    'right now' => [fn () => now(), 'Choose a time in the future.'],
    'too far ahead' => [fn () => now()->addYears(2), 'Choose a date within the next year.'],
]);

test('malformed local dates are rejected', function (string $date, string $time) {
    expect(fn () => $this->service->parseLocal($this->admin->organization, $date, $time))
        ->toThrow(InvalidFollowUpException::class, 'Choose a valid date and time.');
})->with([['2026-02-30', '10:00'], ['10/05/2026', '10:00'], ['2026-10-05', '25:00'], ['', '']]);

test('a follow-up cannot be created for another organization', function () {
    [, $otherCustomer, $otherConversation] = followUpBusiness('Other Co');

    expect(fn () => $this->service->scheduleManual($this->admin, $otherCustomer, now()->addDay()))
        ->toThrow(InvalidFollowUpException::class, 'Customer not found.')
        ->and(fn () => $this->service->scheduleManual($this->admin, $this->customer, now()->addDay(), null, $otherConversation))
        ->toThrow(InvalidFollowUpException::class)
        ->and(fn () => $this->service->scheduleManual($this->admin, $this->customer, now()->addDay(), null, null, User::factory()->create()))
        ->toThrow(InvalidFollowUpException::class);
});

// Status transitions

test('only allowed status transitions are possible', function () {
    expect(FollowUpStatus::Pending->canTransitionTo(FollowUpStatus::Due))->toBeTrue()
        ->and(FollowUpStatus::Due->canTransitionTo(FollowUpStatus::Completed))->toBeTrue()
        ->and(FollowUpStatus::Due->canTransitionTo(FollowUpStatus::Pending))->toBeTrue()
        ->and(FollowUpStatus::Pending->canTransitionTo(FollowUpStatus::Failed))->toBeFalse();

    foreach ([FollowUpStatus::Completed, FollowUpStatus::Cancelled, FollowUpStatus::Skipped, FollowUpStatus::Failed] as $final) {
        expect($final->allowedTransitions())->toBe([]);
    }

    $followUp = $this->service->scheduleManual($this->admin, $this->customer, now()->addDay());
    $this->service->complete($followUp, $this->admin);

    expect(fn () => $this->service->cancel($followUp, $this->admin, FollowUpCancelReason::Duplicate))
        ->toThrow(InvalidFollowUpException::class, 'This follow-up is completed and can\'t be cancelled.')
        ->and(fn () => $this->service->reschedule($followUp, $this->admin, now()->addDays(3)))
        ->toThrow(InvalidFollowUpException::class);
});

test('organization_id is not mass assignable', function () {
    expect(fn () => FollowUp::create(['organization_id' => 999]))->toThrow(MassAssignmentException::class);
});

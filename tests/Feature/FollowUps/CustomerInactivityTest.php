<?php

use App\Enums\CustomerStatus;
use App\Enums\FollowUpSkipReason;
use App\Events\CustomerReplyReceived;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\SendEmailJob;
use App\Models\ConversationEvent;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Services\Customers\CustomerActivityService;
use App\Services\Customers\CustomerService;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendEmailJob::class]);
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    $this->organization = $this->admin->organization;
    allowAutomaticFollowUpEmail($this->organization);
    $this->activity = app(CustomerActivityService::class);
});

function inactivityAttempts(Organization $organization, int $attempts): void
{
    $organization->forceFill(['settings' => array_merge($organization->settings ?? [], ['follow_up_attempts' => $attempts, 'follow_up_delay_days' => 2])])->save();
}

/** Send the follow-up chain (one email by default) and return the last email's follow-up. */
function sendLastFollowUp($conversation): FollowUp
{
    $followUp = automatedFollowUp($conversation);
    makeDue($followUp);

    return $followUp->fresh();
}

test('a customer who does not reply for 10 days after the last follow-up email is marked inactive', function () {
    $last = sendLastFollowUp($this->conversation);
    expect($last->final_attempt)->toBeTrue();

    $this->travel(9)->days();
    expect($this->activity->markUnresponsive())->toBe(0);

    $this->travel(1)->day();
    expect($this->activity->markUnresponsive())->toBe(1);

    expect($this->customer->fresh())
        ->status->toBe(CustomerStatus::Inactive)
        ->inactive_reason->toBe(CustomerActivityService::REASON_NO_REPLY)
        ->and(ConversationEvent::where('type', 'customer_inactive')->where('customer_id', $this->customer->id)->exists())->toBeTrue();
});

test('the scheduler runs the inactivity check', function () {
    $this->artisan('customers:mark-unresponsive')->assertSuccessful();
});

test('a reply after the last follow-up keeps the customer active', function () {
    sendLastFollowUp($this->conversation);
    $this->travel(2)->days();
    customerReply($this->conversation);
    $this->travel(9)->days();

    expect($this->activity->markUnresponsive())->toBe(0)
        ->and($this->customer->fresh()->status)->toBe(CustomerStatus::Active);
});

test('a customer is not marked inactive while more follow-up emails are still to come', function () {
    inactivityAttempts($this->organization, 3);
    $first = sendLastFollowUp($this->conversation);
    expect($first->final_attempt)->toBeFalse();

    $this->travel(11)->days();

    expect($this->activity->markUnresponsive())->toBe(0);
});

test('a reply makes an inactive customer active again straight away', function () {
    sendLastFollowUp($this->conversation);
    $this->travel(10)->days();
    $this->activity->markUnresponsive();

    $reply = customerReply($this->conversation);
    event(new CustomerReplyReceived($this->organization->id, $reply->id, $this->conversation->id, $this->customer->id));

    expect($this->customer->fresh())
        ->status->toBe(CustomerStatus::Active)
        ->inactive_reason->toBeNull()
        ->and(ConversationEvent::where('type', 'customer_reactivated')->where('customer_id', $this->customer->id)->exists())->toBeTrue();
});

test('a customer made active again by hand is not switched off by the same old email', function () {
    sendLastFollowUp($this->conversation);
    $this->travel(10)->days();
    $this->activity->markUnresponsive();

    $this->travel(1)->day();
    app(CustomerService::class)->update($this->admin, $this->customer->fresh(), [
        'first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@example.com', 'status' => 'active',
    ]);
    $this->travel(1)->day();

    expect($this->activity->markUnresponsive())->toBe(0)
        ->and($this->customer->fresh()->status)->toBe(CustomerStatus::Active);
});

test('inactive customers are not emailed: follow-ups are skipped and direct sends are refused', function () {
    $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
    $followUp = automatedFollowUp($this->conversation);

    makeDue($followUp);

    expect($followUp->fresh()->skip_reason)->toBe(FollowUpSkipReason::CustomerInactive)
        ->and(fn () => app(EmailService::class)->sendToConversation($this->conversation->fresh(), 'Hello', null, 'Hi'))
        ->toThrow(EmailSendingNotAllowedException::class, 'marked inactive');
});

test('marking a customer inactive by hand records it as a manual change', function () {
    app(CustomerService::class)->update($this->admin, $this->customer, [
        'first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@example.com', 'status' => 'inactive',
    ]);

    expect($this->customer->fresh())
        ->status->toBe(CustomerStatus::Inactive)
        ->inactive_reason->toBe(CustomerActivityService::REASON_MANUAL)
        ->status_changed_at->not->toBeNull();
});

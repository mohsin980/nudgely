<?php

use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Jobs\SendEmailJob;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use App\Services\Customers\CustomerService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendEmailJob::class]);
    [$this->admin] = followUpBusiness();
    $this->organization = $this->admin->organization;
    allowAutomaticFollowUpEmail($this->organization);

    $automation = app(AutomationTemplates::class)->install($this->organization, $this->admin, 'new_lead_follow_up');
    app(AutomationBuilder::class)->activate($automation, $this->admin);
});

function addLead(string $first = 'Lena', string $email = 'lena@example.com'): Customer
{
    return app(CustomerService::class)->create(test()->admin, ['first_name' => $first, 'last_name' => 'Lead', 'email' => $email]);
}

test('a new customer without an estimate gets a check-in email the next day, in a new conversation', function () {
    $lead = addLead();

    $followUp = FollowUp::where('customer_id', $lead->id)->sole();
    expect($followUp->status)->toBe(FollowUpStatus::Pending)
        ->and($followUp->estimate_id)->toBeNull()
        ->and($followUp->conversation->customer_id)->toBe($lead->id)
        ->and($followUp->conversation->subject)->toBe('Thanks for getting in touch with Dallas HVAC');

    makeDue($followUp);

    $email = Message::where('direction', 'outbound')->sole();
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Completed)
        ->and($email->to_address)->toBe('lena@example.com')
        ->and($email->body_text)->toStartWith('Hi Lena,');
});

test('sending the lead an estimate before the check-in is due skips it', function () {
    $lead = addLead();
    $followUp = FollowUp::where('customer_id', $lead->id)->sole();

    $this->travel(2)->hours();
    // The email job is faked, so mark the estimate as sent the way delivery would.
    sentEstimate($this->admin, $lead)->forceFill(['status' => EstimateStatus::Sent, 'sent_at' => now()])->save();

    makeDue($followUp);

    expect($followUp->fresh()->skip_reason)->toBe(FollowUpSkipReason::EstimateSent);
});

test('a reply from the lead before the check-in is due skips it', function () {
    $lead = addLead();
    $followUp = FollowUp::where('customer_id', $lead->id)->sole();

    $this->travel(2)->hours();
    customerReply($followUp->conversation);
    makeDue($followUp);

    expect($followUp->fresh()->skip_reason)->toBe(FollowUpSkipReason::CustomerReplied)
        ->and(Message::where('direction', 'outbound')->count())->toBe(0);
});

test('the lead email repeats with the follow-up emails setting', function () {
    $this->organization->forceFill(['settings' => ['follow_up_attempts' => 2, 'follow_up_delay_days' => 2]])->save();
    $lead = addLead();
    $first = FollowUp::where('customer_id', $lead->id)->sole();

    makeDue($first);

    expect(FollowUp::where('metadata->repeat_of', $first->id)->exists())->toBeTrue();
});

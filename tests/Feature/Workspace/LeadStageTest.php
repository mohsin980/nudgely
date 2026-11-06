<?php

use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\LeadStage;
use App\Events\CustomerReplyReceived;
use App\Events\EstimateAccepted;
use App\Events\EstimateDeclined;
use App\Jobs\SendEmailJob;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\ShowCustomer;
use App\Models\ConversationEvent;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use App\Services\Customers\CustomerService;
use App\Services\Email\EmailService;
use App\Services\Leads\LeadStageService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    [$this->admin, $this->customer, $this->conversation] = estimateBusiness();
    $this->organization = $this->admin->organization;
    $this->stages = app(LeadStageService::class);
    fakeEmailProvider();
});

function stageOf(Customer $customer): LeadStage
{
    return $customer->fresh()->lead_stage;
}

test('a new customer starts as a new lead', function () {
    $customer = app(CustomerService::class)->create($this->admin, ['first_name' => 'Lena', 'last_name' => 'Lead', 'email' => 'lena@example.com']);

    expect($customer->lead_stage)->toBe(LeadStage::New)
        ->and(stageOf($customer))->toBe(LeadStage::New);
});

test('sending an estimate moves the lead to quoted, accepting it to won', function () {
    $estimate = sentEstimate($this->admin, $this->customer);
    expect(stageOf($this->customer))->toBe(LeadStage::Quoted);

    event(new EstimateAccepted($this->organization->id, $estimate->id, $this->customer->id, $this->conversation->id));

    expect(stageOf($this->customer))->toBe(LeadStage::Won)
        ->and(ConversationEvent::where('type', 'lead_stage_changed')->where('customer_id', $this->customer->id)->pluck('data')->pluck('to')->all())
        ->toContain('quoted', 'won');
});

test('the first email from the business makes a new lead contacted', function () {
    app(EmailService::class)->sendToConversation($this->conversation, 'Hello', null, 'Hi John');

    expect(stageOf($this->customer))->toBe(LeadStage::Contacted);
});

test('a reply makes a new lead contacted, but never moves a lead backwards', function () {
    $reply = customerReply($this->conversation);
    event(new CustomerReplyReceived($this->organization->id, $reply->id, $this->conversation->id, $this->customer->id));
    expect(stageOf($this->customer))->toBe(LeadStage::Contacted);

    $this->customer->forceFill(['lead_stage' => LeadStage::Quoted])->save();
    event(new CustomerReplyReceived($this->organization->id, $reply->id, $this->conversation->id, $this->customer->id));

    expect(stageOf($this->customer))->toBe(LeadStage::Quoted);
});

test('a declined estimate makes the lead lost, unless another estimate is still open', function () {
    $first = sentEstimate($this->admin, $this->customer);
    $second = sentEstimate($this->admin, $this->customer, ['title' => 'Furnace']);

    $first->forceFill(['status' => EstimateStatus::Declined, 'declined_at' => now()])->save();
    event(new EstimateDeclined($this->organization->id, $first->id, $this->customer->id, $this->conversation->id));
    expect(stageOf($this->customer))->toBe(LeadStage::Quoted);

    $second->forceFill(['status' => EstimateStatus::Declined, 'declined_at' => now()])->save();
    event(new EstimateDeclined($this->organization->id, $second->id, $this->customer->id, $this->conversation->id));
    expect(stageOf($this->customer))->toBe(LeadStage::Lost);
});

test('won and lost are kept: automatic changes never reopen them', function () {
    $this->customer->forceFill(['lead_stage' => LeadStage::Lost])->save();

    sentEstimate($this->admin, $this->customer);

    expect(stageOf($this->customer))->toBe(LeadStage::Lost);
});

test('a team member can set any stage by hand, and it shows on the timeline', function () {
    $this->customer->forceFill(['lead_stage' => LeadStage::Won])->save();

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('setLeadStage', 'contacted')
        ->assertSee('Lead stage changed to Contacted by Mohsin');

    expect(stageOf($this->customer))->toBe(LeadStage::Contacted);
});

test('another business cannot change the stage', function () {
    [$otherAdmin] = followUpBusiness('Other Co');

    expect(fn () => $this->stages->set($otherAdmin, $this->customer, LeadStage::Won))
        ->toThrow(NotFoundHttpException::class);
});

test('the customer list shows each stage and filters by it', function () {
    $won = Customer::factory()->for($this->organization)->create(['name' => 'Wendy Won', 'email' => 'wendy@example.com']);
    $won->forceFill(['lead_stage' => LeadStage::Won])->save();

    Livewire::actingAs($this->admin)->test(CustomerIndex::class)
        ->assertSee('All leads')
        ->assertSee('Wendy Won')->assertSee('John Smith')
        ->set('stage', 'won')
        ->assertSee('Wendy Won')->assertDontSee('John Smith');
});

test('the new lead sequence emails on days 1, 3 and 7, and stops when the lead is marked lost', function () {
    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $this->organization->forceFill(['settings' => ['follow_up_attempts' => 3, 'follow_up_delay_days' => 2]])->save();
    $automation = app(AutomationTemplates::class)->install($this->organization, $this->admin, 'new_lead_sequence');
    app(AutomationBuilder::class)->activate($automation, $this->admin);

    $lead = app(CustomerService::class)->create($this->admin, ['first_name' => 'Lena', 'last_name' => 'Lead', 'email' => 'lena@example.com']);
    $steps = FollowUp::where('customer_id', $lead->id)->orderBy('due_at')->get();

    expect($steps)->toHaveCount(3)
        ->and($steps->pluck('subject')->all())->toBe(['Thanks for getting in touch with {{business.name}}', 'Can we help with your project?', 'Still interested?'])
        ->and($steps->map(fn ($step) => $step->due_at->diffInDays(now(), true))->map(fn ($days) => (int) round($days))->all())->toBe([1, 3, 7]);

    // The first email goes out; the sequence's own next steps follow, so it isn't repeated as well.
    makeDue($steps[0]);
    expect(FollowUp::where('metadata->repeat_of', $steps[0]->id)->exists())->toBeFalse();

    $this->stages->set($this->admin, $lead, LeadStage::Lost);
    makeDue($steps[1]);

    expect($steps[1]->fresh()->skip_reason)->toBe(FollowUpSkipReason::LeadClosed);
});

<?php

use App\Enums\LeadStage;
use App\Livewire\Customers\Pipeline;
use App\Livewire\Customers\ShowCustomer;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Services\Customers\CustomerTagService;
use App\Services\Leads\PipelineReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    [$this->admin, $this->customer] = estimateBusiness();
    $this->organization = $this->admin->organization;

    // The sample customer is an old lost deal, so the report numbers in each test are exact.
    $this->customer->forceFill(['lead_stage' => LeadStage::Lost, 'lead_stage_changed_at' => now()->subYears(2), 'deal_value_cents' => null])->save();
});

function pipelineLead($organization, LeadStage $stage, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'lead_stage' => $stage,
        'lead_stage_changed_at' => now(),
    ], $attributes));
}

test('the board groups leads by stage with each column count and value', function () {
    pipelineLead($this->organization, LeadStage::New, ['name' => 'Ann Fresh', 'deal_value_cents' => 10000]);
    pipelineLead($this->organization, LeadStage::New, ['name' => 'Ben Fresh']);
    pipelineLead($this->organization, LeadStage::Quoted, ['name' => 'Cara Quote', 'deal_value_cents' => 50000]);

    $board = app(PipelineReport::class)->board($this->organization);

    expect($board['new']['count'])->toBe(2)
        ->and($board['new']['value'])->toBe(10000)
        ->and($board['quoted']['count'])->toBe(1)
        ->and($board['quoted']['value'])->toBe(50000)
        ->and($board['lost']['count'])->toBe(1);
});

test('the board only shows leads from the signed-in business', function () {
    [$other] = estimateBusiness('Other Trades');
    pipelineLead($other->organization, LeadStage::Quoted, ['deal_value_cents' => 99999]);

    $board = app(PipelineReport::class)->board($this->organization);

    expect($board['quoted']['count'])->toBe(0)
        ->and($board['quoted']['value'])->toBe(0);
});

test('the tag filter narrows the board to tagged leads', function () {
    $vip = pipelineLead($this->organization, LeadStage::Contacted, ['name' => 'Vera Vip']);
    pipelineLead($this->organization, LeadStage::Contacted, ['name' => 'Plain Pat']);
    app(CustomerTagService::class)->add($vip, 'VIP');

    $board = app(PipelineReport::class)->board($this->organization, 'vip');

    expect($board['contacted']['count'])->toBe(1)
        ->and($board['contacted']['customers']->first()->name)->toBe('Vera Vip');
});

test('the report counts open deals, this month\'s wins and the win rate', function () {
    pipelineLead($this->organization, LeadStage::New, ['deal_value_cents' => 10000]);
    pipelineLead($this->organization, LeadStage::New);
    pipelineLead($this->organization, LeadStage::Quoted, ['deal_value_cents' => 50000]);
    pipelineLead($this->organization, LeadStage::Won, ['deal_value_cents' => 30000]);
    pipelineLead($this->organization, LeadStage::Won, ['deal_value_cents' => 20000, 'lead_stage_changed_at' => now()->subYears(2)]);
    pipelineLead($this->organization, LeadStage::Lost, ['deal_value_cents' => 15000]);

    $summary = app(PipelineReport::class)->summary($this->organization);

    expect($summary['open_count'])->toBe(3)
        ->and($summary['open_value'])->toBe(60000)
        ->and($summary['won_count'])->toBe(1)
        ->and($summary['won_value'])->toBe(30000)
        ->and($summary['win_rate'])->toBe(50); // 2 won out of 4 closed (including the old lost sample)
});

test('the win rate is empty until a deal is closed', function () {
    $this->customer->forceFill(['lead_stage' => LeadStage::New, 'lead_stage_changed_at' => now()])->save();

    expect(app(PipelineReport::class)->summary($this->organization)['win_rate'])->toBeNull();
});

test('moving a lead on the pipeline changes its stage', function () {
    $lead = pipelineLead($this->organization, LeadStage::New);

    Livewire::actingAs($this->admin)->test(Pipeline::class)->call('moveLead', $lead->id, 'quoted');

    expect($lead->fresh()->lead_stage)->toBe(LeadStage::Quoted);
});

test('the pipeline cannot move a lead from another business', function () {
    [$other] = estimateBusiness('Other Trades');
    $foreign = pipelineLead($other->organization, LeadStage::New);

    expect(fn () => Livewire::actingAs($this->admin)->test(Pipeline::class)->call('moveLead', $foreign->id, 'quoted'))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->lead_stage)->toBe(LeadStage::New);
});

test('the pipeline ignores an unknown stage and leaves the lead where it is', function () {
    $lead = pipelineLead($this->organization, LeadStage::New);

    Livewire::actingAs($this->admin)->test(Pipeline::class)->call('moveLead', $lead->id, 'bogus');

    expect($lead->fresh()->lead_stage)->toBe(LeadStage::New);
});

test('a deal value is saved in cents, with commas and decimals accepted', function () {
    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('saveDealValue', '2,500.50');

    expect($this->customer->fresh()->deal_value_cents)->toBe(250050);
});

test('a deal value that is not an amount is refused', function () {
    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('saveDealValue', 'lots')
        ->assertHasErrors(['dealValue']);

    expect($this->customer->fresh()->deal_value_cents)->toBeNull();
});

test('a blank deal value clears the value', function () {
    $this->customer->forceFill(['deal_value_cents' => 5000])->save();

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('saveDealValue', '');

    expect($this->customer->fresh()->deal_value_cents)->toBeNull();
});

test('a typed tag is normalised and shared, and adding it twice does not duplicate it', function () {
    $component = Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id]);

    $component->set('tagName', 'Ready to book!')->call('addTag');
    $component->set('tagName', 'ready to book')->call('addTag');

    expect($this->customer->fresh()->tags->pluck('slug')->all())->toBe(['ready-to-book'])
        ->and(CustomerTag::query()->where('organization_id', $this->organization->id)->count())->toBe(1);
});

test('removing a tag takes it off that customer only', function () {
    $service = app(CustomerTagService::class);
    $tag = $service->add($this->customer, 'Repeat');
    $other = pipelineLead($this->organization, LeadStage::New);
    $service->add($other, 'Repeat');

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('removeTag', $tag->id);

    expect($this->customer->fresh()->tags)->toHaveCount(0)
        ->and($other->fresh()->tags)->toHaveCount(1);
});

test('a customer can carry at most ten tags', function () {
    $service = app(CustomerTagService::class);

    for ($i = 1; $i <= CustomerTagService::MAX_PER_CUSTOMER; $i++) {
        $service->add($this->customer, "Tag {$i}");
    }

    expect(fn () => $service->add($this->customer, 'Tag 11'))->toThrow(ValidationException::class)
        ->and($this->customer->fresh()->tags)->toHaveCount(CustomerTagService::MAX_PER_CUSTOMER);
});

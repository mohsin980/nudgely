<?php

use App\Enums\EstimateStatus;
use App\Livewire\Dashboard;
use App\Models\Customer;
use App\Models\Estimate;
use App\Services\Dashboard\DashboardService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    // Monday 5 Oct 2026, 10:00 in Chicago (the default business timezone).
    $this->travelTo(CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'));
    [$this->admin, $this->john] = followUpBusiness('Dallas HVAC');
});

function acceptedEstimate(Customer $customer, string $total, CarbonImmutable $acceptedAt): Estimate
{
    return Estimate::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'status' => EstimateStatus::Accepted,
        'total' => $total,
        'sent_at' => $acceptedAt->subDays(2),
        'accepted_at' => $acceptedAt,
    ]);
}

test('won this week counts only quotes accepted since Monday in the business timezone', function () {
    acceptedEstimate($this->john, '1250.00', CarbonImmutable::parse('2026-10-05 15:30:00', 'UTC')); // Monday, this week
    acceptedEstimate($this->john, '300.00', CarbonImmutable::parse('2026-10-02 15:00:00', 'UTC'));  // last Friday

    $won = app(DashboardService::class)->acceptedThisWeek($this->admin->organization);

    expect($won)->toBe(['count' => 1, 'cents' => 125000]);
});

test('the dashboard shows the Jobs strip with open quotes, won this week and follow-ups today', function () {
    acceptedEstimate($this->john, '1250.00', CarbonImmutable::parse('2026-10-05 15:30:00', 'UTC'));
    Estimate::factory()->create([
        'customer_id' => $this->john->id,
        'organization_id' => $this->john->organization_id,
        'status' => EstimateStatus::Sent,
        'total' => '400.00',
        'sent_at' => CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'),
    ]);

    $page = Livewire::actingAs($this->admin)->test(Dashboard::class);
    $page->call('$refresh');

    $org = $this->admin->organization;
    $page->assertSeeHtml('data-jobs-card="open-quotes"')
        ->assertSeeHtml('data-jobs-card="won-this-week"')
        ->assertSeeHtml('data-jobs-card="follow-up-today"')
        ->assertSee(Money::format(40000, $org->currency))
        ->assertSee(Money::format(125000, $org->currency));
});

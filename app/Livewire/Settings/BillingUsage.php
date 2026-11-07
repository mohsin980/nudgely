<?php

namespace App\Livewire\Settings;

use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\ManagesSubscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Usage against the plan's limits, counted by UsageService for the current billing period.
 */
#[Layout('components.layouts.app')]
#[Title('Usage')]
class BillingUsage extends Component
{
    use ManagesSubscription;

    public function mount(): void
    {
        $this->authorize(Permission::ManageBilling->value);
    }

    public function render(EntitlementService $entitlements, UsageService $usage, BillingService $billing)
    {
        $organization = $this->organization();
        [$start, $end] = $usage->period($organization);
        $subscription = $billing->currentSubscription($organization);

        return view('livewire.settings.billing-usage', [
            'organization' => $organization,
            'plan' => $entitlements->plan($organization),
            'rows' => $entitlements->summary($organization),
            'periodStart' => $start,
            'periodEnd' => $end->subSecond(),
            'nextBilling' => $subscription ? $this->nextBilling($subscription) : null,
            'payment' => $this->payment($billing),
        ]);
    }
}

<?php

namespace App\Livewire\Settings;

use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Read-only billing overview (owner): current plan and subscription, usage against limits,
 * and the available plans. Checkout and plan changes come with the payment provider.
 */
#[Layout('components.layouts.app')]
#[Title('Billing')]
class BillingOverview extends Component
{
    use SettingsPage;

    public function mount(): void
    {
        $this->authorize(Permission::ManageBilling->value);
    }

    public function render(EntitlementService $entitlements, BillingService $billing, UsageService $usage)
    {
        $organization = $this->organization();
        [$start, $end] = $usage->period($organization);

        return view('livewire.settings.billing-overview', [
            'organization' => $organization,
            'plan' => $entitlements->plan($organization),
            'subscription' => $billing->currentSubscription($organization),
            'rows' => $entitlements->summary($organization),
            'plans' => $billing->plans(),
            'periodStart' => $start,
            'periodEnd' => $end->subSecond(),
        ]);
    }
}

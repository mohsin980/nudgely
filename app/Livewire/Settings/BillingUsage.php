<?php

namespace App\Livewire\Settings;

use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
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
    use SettingsPage;

    public function mount(): void
    {
        $this->authorize(Permission::ManageBilling->value);
    }

    public function render(EntitlementService $entitlements, UsageService $usage)
    {
        $organization = $this->organization();
        [$start, $end] = $usage->period($organization);

        return view('livewire.settings.billing-usage', [
            'organization' => $organization,
            'plan' => $entitlements->plan($organization),
            'rows' => $entitlements->summary($organization),
            'periodStart' => $start,
            'periodEnd' => $end->subSecond(),
        ]);
    }
}

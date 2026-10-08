<?php

namespace App\Livewire\Settings;

use App\Billing\Plan;
use App\Enums\Billing\LimitKey;
use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\ManagesSubscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Compare plans and change plan. Plans, prices and limits come from PlanCatalog (config/billing.php).
 */
#[Layout('components.layouts.app')]
#[Title('Plans')]
class BillingPlans extends Component
{
    use ManagesSubscription;

    public function mount(): void
    {
        $this->authorize(Permission::ManageBilling->value);
    }

    public function render(EntitlementService $entitlements, BillingService $billing)
    {
        $organization = $this->organization();
        $summary = $entitlements->summary($organization);

        return view('livewire.settings.billing-plans', [
            'organization' => $organization,
            'plan' => $entitlements->plan($organization),
            'subscription' => $billing->currentSubscription($organization),
            'plans' => $billing->plans(),
            'trialDays' => $billing->trialDaysFor($organization),
            'featureLabels' => config('billing.feature_labels', []),
            'overLimits' => fn (Plan $target) => $this->overLimits($target, $summary),
        ]);
    }

    /**
     * Limits the business already exceeds on a smaller plan (data is kept; new items would be limited).
     *
     * @param  array<string, array{label: string, used: int}>  $summary
     * @return list<string>
     */
    private function overLimits(Plan $target, array $summary): array
    {
        $over = [];

        foreach (LimitKey::cases() as $key) {
            $limit = $target->limit($key);

            if ($limit !== null && ($summary[$key->value]['used'] ?? 0) > $limit) {
                $over[] = "{$summary[$key->value]['label']}: {$summary[$key->value]['used']} of {$limit}";
            }
        }

        return $over;
    }
}

<?php

namespace App\Exceptions\Billing;

use App\Billing\Plan;
use App\Enums\Billing\LimitKey;

/**
 * The current plan doesn't allow adding more of something. The message is safe to show and says
 * what to do; the properties let callers offer the upgrade (the plan that would lift the limit).
 */
class PlanLimitException extends BillingException
{
    public function __construct(
        public readonly LimitKey $key,
        public readonly int $limit,
        public readonly string $planName,
        public readonly ?Plan $upgradePlan = null,
    ) {
        parent::__construct("Your {$planName} plan allows up to ".number_format($limit)." {$key->noun()}. "
            .($upgradePlan ? "Upgrade to {$upgradePlan->name} to add more." : 'Upgrade your plan to add more.'));
    }

    /**
     * Where the owner changes plan.
     */
    public function upgradeUrl(): string
    {
        return route('settings.billing');
    }
}

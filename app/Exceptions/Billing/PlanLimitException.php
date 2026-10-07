<?php

namespace App\Exceptions\Billing;

use App\Enums\Billing\LimitKey;

/**
 * The current plan doesn't allow adding more of something. The message is safe to show and says what to do.
 */
class PlanLimitException extends BillingException
{
    public function __construct(public readonly LimitKey $key, public readonly int $limit, string $planName)
    {
        parent::__construct("Your {$planName} plan allows up to ".number_format($limit)." {$key->noun()}. Upgrade your plan to add more.");
    }
}

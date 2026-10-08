<?php

namespace App\Jobs\Billing;

use App\Services\Billing\BillingLifecycle;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled (hourly): past-due subscriptions whose grace period is over. Idempotent: each
 * subscription is restricted (and the owner told) once.
 */
class CheckGracePeriodsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3300;

    public function handle(BillingLifecycle $lifecycle): void
    {
        $lifecycle->checkGracePeriods();
    }
}

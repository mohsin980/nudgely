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

    /** Stripe is called once per overdue subscription; keep under the queue's retry_after. */
    public int $timeout = 240;

    /** Hourly and idempotent: a failed run is simply retried a few times, then the next hour picks it up. */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function handle(BillingLifecycle $lifecycle): void
    {
        $lifecycle->checkGracePeriods();
    }
}

<?php

namespace App\Jobs\Billing;

use App\Services\Billing\BillingLifecycle;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled (hourly): sign-up trials that ran out. Idempotent: each organization is claimed once.
 */
class ExpireTrialsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3300;

    public int $timeout = 240;

    /** Hourly and idempotent: a failed run is simply retried a few times, then the next hour picks it up. */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function handle(BillingLifecycle $lifecycle): void
    {
        $lifecycle->expireTrials();
    }
}

<?php

namespace App\Jobs;

use App\Services\LeadAds\LeadAdsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetch one Facebook or Instagram lead and turn it into a customer. A lead Meta can't return yet stays pending
 * and is retried by the queue, then by lead-ads:sync.
 */
class ImportLeadAdJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $leadId) {}

    public function handle(LeadAdsService $leadAds): void
    {
        $leadAds->import($this->leadId);
    }
}

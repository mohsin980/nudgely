<?php

namespace App\Console\Commands;

use App\Services\LeadAds\LeadAdsService;
use Illuminate\Console\Command;

class SyncLeadAdsCommand extends Command
{
    protected $signature = 'lead-ads:sync';

    protected $description = 'Pick up Facebook and Instagram leads a webhook missed, and retry pending ones';

    public function handle(LeadAdsService $leadAds): int
    {
        if (! LeadAdsService::enabled()) {
            return self::SUCCESS;
        }

        $this->info('Found '.$leadAds->sync().' new leads.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Leads\LeadOutcomeReminders;
use Illuminate\Console\Command;

class RemindLeadOutcomesCommand extends Command
{
    protected $signature = 'leads:remind-outcomes';

    protected $description = 'Remind the business to mark quiet Qualified and Quoted leads as Won or Lost';

    public function handle(LeadOutcomeReminders $reminders): int
    {
        $this->info('Reminded about '.$reminders->send().' leads.');

        return self::SUCCESS;
    }
}

<?php

use App\Services\Automation\FollowUpProcessor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('automations:process-follow-ups', function (FollowUpProcessor $followUps) {
    $this->info($followUps->dispatchDue().' due follow-up(s) queued.');
})->purpose('Queue due automation follow-ups');

Schedule::command('automations:process-follow-ups')->everyMinute()->withoutOverlapping();

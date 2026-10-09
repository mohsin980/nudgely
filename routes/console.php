<?php

use App\Services\Automation\AutomationEngine;
use App\Services\Billing\BillingService;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('follow-ups:process-due', function (FollowUpProcessor $followUps) {
    $this->info($followUps->markDue().' follow-up(s) marked due and queued.');
})->purpose('Mark due follow-ups and queue them for processing');

Artisan::command('follow-ups:notify-overdue', function (FollowUpProcessor $followUps) {
    $this->info($followUps->notifyOverdue().' overdue follow-up notification(s) sent.');
})->purpose('Notify the business about overdue follow-ups');

Artisan::command('automations:resume-waiting', function (AutomationEngine $engine) {
    $this->info($engine->resumeDue().' waiting automation run(s) resumed.');
})->purpose('Continue automation runs whose WAIT is over');

Artisan::command('estimates:expire', function (EstimateService $estimates) {
    $this->info($estimates->expireDue().' estimate(s) expired.');
})->purpose('Mark sent estimates past their "valid until" date as expired');

Artisan::command('billing:sync-subscriptions', function (BillingService $billing) {
    $result = $billing->refreshAll();
    $this->info("{$result['synced']} subscription(s) synced, {$result['failed']} failed.");
})->purpose('Synchronize current subscriptions with the billing provider (until webhooks exist)');

Artisan::command('billing:send-trial-reminders', function (BillingService $billing) {
    $this->info($billing->sendTrialReminders().' trial reminder(s) sent.');
})->purpose('Remind owners whose free trial is about to end');

Schedule::command('follow-ups:process-due')->everyMinute()->withoutOverlapping();
Schedule::command('follow-ups:notify-overdue')->hourly()->withoutOverlapping();
Schedule::command('estimates:expire')->hourly()->withoutOverlapping();
Schedule::command('automations:resume-waiting')->everyMinute()->withoutOverlapping();
Schedule::command('billing:sync-subscriptions')->hourly()->withoutOverlapping();
Schedule::command('billing:send-trial-reminders')->dailyAt('09:00')->withoutOverlapping();

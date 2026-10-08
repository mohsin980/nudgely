<?php

use App\Jobs\Billing\CheckGracePeriodsJob;
use App\Jobs\Billing\ExpireTrialsJob;
use App\Services\Automation\AutomationEngine;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\BillingService;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Reliability\OperationsReport;
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
})->purpose('Reconcile current subscriptions with the billing provider (safety net for missed webhooks)');

Artisan::command('billing:send-trial-reminders', function (BillingService $billing) {
    $this->info($billing->sendTrialReminders().' trial reminder(s) sent.');
})->purpose('Remind owners whose free trial is about to end');

Artisan::command('billing:expire-trials', function (BillingLifecycle $lifecycle) {
    $this->info($lifecycle->expireTrials().' trial(s) expired.');
})->purpose('End sign-up trials that ran out: move to Free limits, record it and tell the owner (nothing is deleted)');

Artisan::command('billing:check-grace-periods', function (BillingLifecycle $lifecycle) {
    $result = $lifecycle->checkGracePeriods();
    $this->info("{$result['restricted']} restricted, {$result['recovered']} recovered, {$result['failed']} provider check(s) failed.");
})->purpose('Restrict subscriptions whose payment is still overdue after the grace period');

Artisan::command('email:recover-stuck-sends', function (EmailService $emails) {
    $this->info($emails->recoverStuckSends((int) config('reliability.email.stuck_sending_minutes')).' stuck email(s) marked failed. None were resent.');
})->purpose('Mark emails left "sending" by a crashed worker as failed, without resending them');

Artisan::command('automations:recover-stalled', function (AutomationEngine $engine) {
    $this->info($engine->recoverStalled((int) config('reliability.automation.stalled_after_minutes')).' stalled automation step(s) queued again.');
})->purpose('Queue again automation steps and runs that nothing is working on');

Artisan::command('retention:prune', function (RetentionPruner $pruner) {
    foreach ($pruner->prune() as $table => $deleted) {
        $this->line("{$table}: {$deleted} deleted");
    }
})->purpose('Delete processed webhook deliveries and expired unused invitations and reply addresses past their retention period');

Artisan::command('reliability:report', function (OperationsReport $report) {
    $this->line(json_encode($report->build(), JSON_PRETTY_PRINT));
})->purpose('Show background work by state: follow-ups, emails, automation runs, queue and failed job classes (no payloads)');

// Every scheduled task: no overlap on this server, and no duplicate run across servers (onOneServer
// uses the cache lock, which the database cache store supports).
Schedule::command('follow-ups:process-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('follow-ups:notify-overdue')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('estimates:expire')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('automations:resume-waiting')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('automations:recover-stalled')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('email:recover-stuck-sends')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('billing:sync-subscriptions')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('billing:send-trial-reminders')->dailyAt('09:00')->withoutOverlapping()->onOneServer();
Schedule::command('retention:prune')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::job(new ExpireTrialsJob)->hourlyAt(5)->name('billing-expire-trials')->withoutOverlapping()->onOneServer();
Schedule::job(new CheckGracePeriodsJob)->hourlyAt(35)->name('billing-check-grace-periods')->withoutOverlapping()->onOneServer();

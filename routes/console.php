<?php

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

Artisan::command('estimates:expire', function (EstimateService $estimates) {
    $this->info($estimates->expireDue().' estimate(s) expired.');
})->purpose('Mark sent estimates past their "valid until" date as expired');

Schedule::command('follow-ups:process-due')->everyMinute()->withoutOverlapping();
Schedule::command('follow-ups:notify-overdue')->hourly()->withoutOverlapping();
Schedule::command('estimates:expire')->hourly()->withoutOverlapping();

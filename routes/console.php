<?php

use App\Enums\Billing\WebhookEventStatus;
use App\Exceptions\Webhooks\WebhookReplayException;
use App\Jobs\Billing\CheckGracePeriodsJob;
use App\Jobs\Billing\ExpireTrialsJob;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Automation\AutomationEngine;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\BillingService;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Reliability\OperationsReport;
use App\Services\Webhooks\WebhookReplayService;
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

Artisan::command('webhooks:failed {--organization= : Only this organization} {--limit=20}', function () {
    $rows = WebhookEvent::query()
        ->where('status', WebhookEventStatus::Failed)
        ->when($this->option('organization'), fn ($query, $org) => $query->where('organization_id', (int) $org))
        ->orderByDesc('failed_at')
        ->limit((int) $this->option('limit'))
        ->get(['id', 'provider', 'event_type', 'organization_id', 'attempt_count', 'failure_reason', 'correlation_id', 'failed_at']);

    $this->table(['ID', 'Provider', 'Type', 'Org', 'Attempts', 'Reason', 'Correlation', 'Failed at'], $rows->map(fn ($e) => [
        $e->id, $e->provider, $e->event_type, $e->organization_id ?? '-', $e->attempt_count, $e->failure_reason, $e->correlation_id ?? '-', $e->failed_at?->toIso8601String(),
    ])->all());
})->purpose('List failed webhook events: what failed, for which organization, how often it was tried');

Artisan::command('webhooks:replay {id : Webhook event ID} {--organization= : Only if the event belongs to this organization}', function (WebhookReplayService $replays) {
    try {
        $event = $replays->replay((int) $this->argument('id'), $this->option('organization') === null ? null : (int) $this->option('organization'));
        $this->info("Event {$event->id} queued for processing again (status: {$event->status->value}).");
    } catch (WebhookReplayException $e) {
        $this->error($e->getMessage());

        return 1;
    }
})->purpose('Process a failed webhook event again. Safe: the original identity is kept and processing is idempotent');

Artisan::command('email:trace {message : Message ID}', function () {
    $message = Message::query()->find((int) $this->argument('message'));

    if ($message === null) {
        $this->error('Message not found.');

        return 1;
    }

    $this->line(json_encode([
        'message_id' => $message->id,
        'organization_id' => $message->organization_id,
        'conversation_id' => $message->conversation_id,
        'direction' => $message->direction->value,
        'provider' => $message->provider?->value,
        'provider_message_id' => $message->provider_message_id,
        'status' => $message->status->value,
        'send_attempts' => $message->send_attempts,
        'failure_reason' => $message->failure_reason,
        'correlation_id' => $message->correlation_id,
        'created_at' => $message->created_at?->toIso8601String(),
        'sent_at' => $message->sent_at?->toIso8601String(),
        'delivered_at' => $message->delivered_at?->toIso8601String(),
        'bounced_at' => $message->bounced_at?->toIso8601String(),
        'complained_at' => $message->complained_at?->toIso8601String(),
        'failed_at' => $message->failed_at?->toIso8601String(),
        // Addresses and bodies are not printed here; use the inbox for the content.
    ], JSON_PRETTY_PRINT));

    $events = WebhookEvent::query()
        ->where('organization_id', $message->organization_id)
        ->where('provider', $message->provider?->value)
        ->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)
        // Bounce and complaint events are keyed by their own ID, so match the message ID inside the payload.
        ->whereRaw("payload->>'MessageID' = ?", [$message->provider_message_id])
        ->get(['id', 'status', 'attempt_count', 'failure_reason', 'correlation_id', 'received_at']);

    $this->line('Provider events: '.$events->count());
    foreach ($events as $event) {
        $this->line("  #{$event->id} {$event->status->value} attempts={$event->attempt_count} ".($event->failure_reason ?? ''));
    }
})->purpose('Show one outbound message: status, timestamps, attempts, correlation ID and its provider events');

Artisan::command('webhooks:check', function () {
    $postmark = config('email.providers.postmark', []);
    $configured = fn (?string $value) => filled($value) ? 'configured' : 'NOT CONFIGURED (endpoint rejects every request)';

    // Only presence is reported. Usernames and secrets are never printed.
    $this->table(['Endpoint', 'Credential', 'URL to set in Postmark'], [
        ['Inbound replies', $configured($postmark['inbound_webhook_secret'] ?? null), url('/webhooks/email/inbound/postmark')],
        ['Delivery events', $configured($postmark['events_webhook_secret'] ?? null), url('/webhooks/email/events/postmark')],
    ]);

    $this->line('Both URLs take Basic auth in the form https://USERNAME:PASSWORD@host/... using the values in your environment.');

    return filled($postmark['events_webhook_secret'] ?? null) && filled($postmark['inbound_webhook_secret'] ?? null) ? 0 : 1;
})->purpose('Show whether the Postmark webhook credentials are set, without printing them');

<?php

/*
|--------------------------------------------------------------------------
| Background work reliability
|--------------------------------------------------------------------------
|
| How long work may sit before the sweeps in routes/console.php treat it as lost, and how long
| operational records are kept. Retention never touches business records (customers, estimates,
| messages, automation runs, follow-ups): only technical logs and expired one-time tokens.
|
*/

return [

    'follow_ups' => [
        // A due follow-up with no job for this long is queued again.
        'stranded_after_minutes' => (int) env('RELIABILITY_FOLLOW_UP_STRANDED_MINUTES', 15),
    ],

    'email' => [
        // An email still "sending" after this long is marked failed and never resent.
        'stuck_sending_minutes' => (int) env('RELIABILITY_STUCK_EMAIL_MINUTES', 15),
    ],

    'automation' => [
        // A pending step, or a running run with nothing left to do, for this long is queued again.
        'stalled_after_minutes' => (int) env('RELIABILITY_STALLED_RUN_MINUTES', 15),
    ],

    'estimates' => [
        // Estimates expired per locked batch by estimates:expire. Smaller batches hold locks for less time.
        'expiry_batch_size' => (int) env('RELIABILITY_ESTIMATE_EXPIRY_BATCH', 500),
    ],

    'retention' => [
        'webhook_events_days' => 90,
        'billing_webhook_events_days' => 180,
        'expired_invitations_days' => 30,
        'expired_reply_routes_days' => 30,
        // A failed webhook keeps its metadata for troubleshooting; its payload (customer email content) goes after this.
        'failed_webhook_payload_days' => (int) env('RELIABILITY_FAILED_WEBHOOK_PAYLOAD_DAYS', 30),
        'chunk_size' => 500,
    ],

];

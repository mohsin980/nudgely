<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Timezone
    |--------------------------------------------------------------------------
    |
    | Follow-up times are stored in UTC and shown in the organization's timezone.
    | Organizations that haven't chosen one use this (US Central). It can be
    | changed per organization under Settings → Business.
    |
    */

    'default_timezone' => env('FOLLOW_UPS_DEFAULT_TIMEZONE', 'America/Chicago'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Follow-Up Email Limits
    |--------------------------------------------------------------------------
    |
    | Per organization. When a limit is reached the follow-up stays due and the
    | owner is notified, so nothing is lost and nothing is sent in bulk.
    | min_interval_hours: no more than one automated follow-up email to the same
    | customer within this many hours.
    |
    */

    'limits' => [
        'max_emails_per_hour' => (int) env('FOLLOW_UPS_MAX_EMAILS_PER_HOUR', 10),
        'max_emails_per_day' => (int) env('FOLLOW_UPS_MAX_EMAILS_PER_DAY', 50),
        'min_interval_hours' => (int) env('FOLLOW_UPS_MIN_INTERVAL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | batch_size: follow-ups marked due per scheduler run (every minute).
    | overdue_after_hours: when a due follow-up counts as overdue for the
    | "is overdue" notification. max_days_ahead: furthest allowed due date.
    |
    */

    'batch_size' => 500,
    'overdue_after_hours' => 24,
    'max_days_ahead' => 365,

];

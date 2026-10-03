<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automation Engine
    |--------------------------------------------------------------------------
    |
    | A global switch for the automation engine. Each organization also has its own
    | automations_enabled setting; both must be on for automations to run.
    |
    */

    'enabled' => (bool) env('AUTOMATIONS_ENABLED', true),

    'queue' => env('AUTOMATION_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Safety Limits
    |--------------------------------------------------------------------------
    |
    | max_chain_depth: how many times automations may trigger further automations
    | (an action raising an event that runs another automation) before the chain stops.
    | max_automations_per_event: automations evaluated for one event; extras are ignored.
    | max_actions_per_run: actions executed per run; extras are recorded as skipped.
    | max_automated_emails_per_hour: automated emails one organization may send per hour.
    |
    */

    'limits' => [
        'max_chain_depth' => (int) env('AUTOMATION_MAX_CHAIN_DEPTH', 10),
        'max_automations_per_event' => (int) env('AUTOMATION_MAX_AUTOMATIONS_PER_EVENT', 25),
        'max_actions_per_run' => (int) env('AUTOMATION_MAX_ACTIONS_PER_RUN', 10),
        'max_automated_emails_per_hour' => (int) env('AUTOMATION_MAX_EMAILS_PER_HOUR', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Only temporary failures (lost database connection, deadlock, a transient
    | provider error) are retried. Configuration or permission problems fail at once.
    | stale_after_seconds lets a retry reclaim an action whose worker died mid-run.
    |
    */

    'retries' => [
        'tries' => (int) env('AUTOMATION_ACTION_TRIES', 3),
        'backoff' => [10, 60],
        'stale_after_seconds' => 600,
    ],

];

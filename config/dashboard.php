<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | new_replies_hours: "New Customer Replies" counts customer messages from the last N hours.
    | refresh_seconds: how often the dashboard re-checks (polling; no WebSockets).
    | high_priority_min_confidence: an AI intent only makes an item HIGH priority when the
    | classification is at least this confident; below it the item is capped at MEDIUM.
    |
    */

    'new_replies_hours' => (int) env('DASHBOARD_NEW_REPLIES_HOURS', 24),

    'refresh_seconds' => 60,

    'high_priority_min_confidence' => 0.7,

    'limits' => [
        'attention' => 10,
        'follow_ups' => 8,
        'replies' => 6,
        'automation_runs' => 5,
        'notifications' => 5,
    ],

];

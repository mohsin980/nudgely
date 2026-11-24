<?php

return [
    // Limits per channel, so a campaign can't flood a provider or get the sending accounts blocked.
    'max_per_minute' => (int) env('OUTREACH_MAX_PER_MINUTE', 60),

    // A contact who was messaged on a channel in the last N days is not messaged again on it.
    'recontact_days' => (int) env('OUTREACH_RECONTACT_DAYS', 7),

    // Largest CSV the import accepts in one go. Bigger lists are split.
    'import_max_rows' => (int) env('OUTREACH_IMPORT_MAX_ROWS', 5000),

    'email' => [
        'postmark_token' => env('OUTREACH_POSTMARK_TOKEN'),
        'message_stream' => env('OUTREACH_POSTMARK_MESSAGE_STREAM', 'outbound'),
        'from_email' => env('OUTREACH_FROM_EMAIL'),
        'from_name' => env('OUTREACH_FROM_NAME', 'Nudgely'),
    ],

    'whatsapp' => [
        'token' => env('OUTREACH_WHATSAPP_TOKEN'),
        'phone_number_id' => env('OUTREACH_WHATSAPP_PHONE_NUMBER_ID'),
    ],
];

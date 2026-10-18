<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business (Cloud API via Meta)
    |--------------------------------------------------------------------------
    |
    | Each business connects its own WhatsApp Business number through Meta's
    | Embedded Signup. Access tokens are stored encrypted per connection and
    | are never sent to the browser. Nothing is sent to Meta unless enabled.
    |
    */

    'enabled' => (bool) env('WHATSAPP_ENABLED', false),

    // Meta app credentials (one app for the whole platform, not per business).
    'app_id' => env('WHATSAPP_APP_ID'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    // Embedded Signup configuration created in the Meta app dashboard.
    'signup_config_id' => env('WHATSAPP_SIGNUP_CONFIG_ID'),
    // Shared secret echoed back by Meta during webhook verification (GET).
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'graph' => [
        'url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),
    ],

    // Approved template used to reach a customer once the 24-hour customer-service window has closed.
    'follow_up_template' => [
        'name' => env('WHATSAPP_FOLLOW_UP_TEMPLATE', 'quote_follow_up'),
        'language' => env('WHATSAPP_FOLLOW_UP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'window_hours' => 24,

    'max_payload_kb' => (int) env('WHATSAPP_MAX_PAYLOAD_KB', 512),

];

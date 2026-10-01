<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transactional Email Provider
    |--------------------------------------------------------------------------
    |
    | The provider QuoteFlow uses to register and verify organizations'
    | sending domains. Credentials stay server-side and are never stored
    | on email connections or sent to the browser.
    |
    | Supported: "postmark"
    |
    */

    'provider' => env('QUOTE_FLOW_EMAIL_PROVIDER', 'postmark'),

    'providers' => [

        'postmark' => [
            // Domains are managed with an Account API token, not a Server token.
            'account_token' => env('POSTMARK_ACCOUNT_TOKEN'),
            'base_url' => env('POSTMARK_API_URL', 'https://api.postmarkapp.com'),
            'timeout' => (int) env('POSTMARK_TIMEOUT', 15),
            'return_path_subdomain' => env('POSTMARK_RETURN_PATH_SUBDOMAIN', 'pm-bounces'),
        ],

    ],

];

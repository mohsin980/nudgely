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
            // Domains are managed with an Account API token; email is sent with a Server token.
            'account_token' => env('POSTMARK_ACCOUNT_TOKEN'),
            'server_token' => env('POSTMARK_SERVER_TOKEN'),
            'message_stream' => env('POSTMARK_MESSAGE_STREAM', 'outbound'),
            // Inbound webhook: Basic auth credentials embedded in the webhook URL configured in Postmark.
            'inbound_webhook_username' => env('POSTMARK_INBOUND_WEBHOOK_USERNAME', 'postmark'),
            'inbound_webhook_secret' => env('POSTMARK_INBOUND_WEBHOOK_SECRET'),
            // Delivery events (delivered, bounced, spam complaint): a separate Basic auth credential, so it can be rotated alone.
            'events_webhook_username' => env('POSTMARK_EVENTS_WEBHOOK_USERNAME', 'postmark'),
            'events_webhook_secret' => env('POSTMARK_EVENTS_WEBHOOK_SECRET'),
            'base_url' => env('POSTMARK_API_URL', 'https://api.postmarkapp.com'),
            'timeout' => (int) env('POSTMARK_TIMEOUT', 15),
            'return_path_subdomain' => env('POSTMARK_RETURN_PATH_SUBDOMAIN', 'pm-bounces'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Replies
    |--------------------------------------------------------------------------
    |
    | Customer replies go to reply+<token>@<reply_domain>. The domain's MX must
    | point at the provider's inbound service, which posts to our webhook.
    |
    */

    'inbound' => [
        'reply_domain' => env('QUOTE_FLOW_INBOUND_REPLY_DOMAIN', 'inbound.quoteflow.ai'),
        // Days a reply address keeps working; empty for no expiry.
        'reply_route_ttl_days' => env('QUOTE_FLOW_REPLY_ROUTE_TTL_DAYS', 365),
        'max_payload_kb' => (int) env('QUOTE_FLOW_INBOUND_MAX_PAYLOAD_KB', 10240),
        'max_body_kb' => (int) env('QUOTE_FLOW_INBOUND_MAX_BODY_KB', 512),
    ],

];

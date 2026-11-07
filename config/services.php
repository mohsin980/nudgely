<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Each business connects its own WhatsApp number (Meta WhatsApp Cloud API); QuoteFlow holds no Meta account.
    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'timeout' => 10,
        // Photos, voice notes, videos and documents customers send are downloaded (Meta keeps them only ~30 days),
        // stored privately, and deleted after media_keep_days. Larger files are not downloaded.
        'media_max_mb' => (int) env('WHATSAPP_MEDIA_MAX_MB', 25),
        'media_keep_days' => (int) env('WHATSAPP_MEDIA_KEEP_DAYS', 180),
        // Voice notes are transcribed to text by the AI model chain (Gemini, Groq Whisper or OpenAI Whisper),
        // so the team can read them and the AI assistant can answer them.
        'transcribe_voice_notes' => (bool) env('WHATSAPP_TRANSCRIBE_VOICE_NOTES', true),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Stripe billing (keys only from the environment; never committed).
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        'timeout' => (int) env('STRIPE_TIMEOUT', 15),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

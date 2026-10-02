<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Used only to classify customer replies. AI output is treated as untrusted
    | data and never triggers actions. Credentials stay server-side.
    |
    | Supported: "openai"
    |
    */

    'provider' => env('QUOTE_FLOW_AI_PROVIDER', 'openai'),

    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            // Must support Structured Outputs (json_schema response format).
            'model' => env('OPENAI_CLASSIFICATION_MODEL', 'gpt-4.1-mini'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Reply Classification
    |--------------------------------------------------------------------------
    */

    'classification' => [
        'enabled' => (bool) env('QUOTE_FLOW_AI_CLASSIFICATION_ENABLED', true),

        // confidence >= high → high; >= medium → medium; otherwise low (always reviewed).
        'thresholds' => [
            'high' => 0.85,
            'medium' => 0.60,
        ],

        // The application's review policy: these intents always need a person, whatever the AI says.
        'always_review_intents' => ['price_objection', 'complaint', 'unclear', 'wants_callback'],

        // Data minimization: what conversation context the AI may see.
        'context' => [
            'previous_messages' => 4,
            'max_latest_chars' => 4000,
            'max_previous_chars' => 1500,
        ],

        'summary_max_length' => 300,
    ],

];

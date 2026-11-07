<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Models
    |--------------------------------------------------------------------------
    |
    | Every AI feature goes through one chain of models. They are tried in the
    | order of AI_MODEL_CHAIN: when one is rate-limited (e.g. a free tier's
    | daily cap), down, or answers with something unusable, the next one is
    | used, and the limited one rests for a while before it is tried again.
    | A model is only used when its key is set (Ollama: when its URL is set).
    |
    | All of them speak the OpenAI Chat Completions format, so any compatible
    | service can be added here. "json" is how a model is asked for JSON:
    | "schema" (it enforces a JSON schema), "object" (it promises valid JSON)
    | or "prompt" (the instructions ask for it). With "object" and "prompt"
    | the schema is written into the prompt. AI output is always validated
    | again on our side and treated as untrusted data.
    |
    */

    'provider' => env('QUOTE_FLOW_AI_PROVIDER', 'chain'),

    'chain' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_MODEL_CHAIN', 'gemini,groq,openrouter,ollama,openai'))))),

    'models' => [

        'gemini' => [
            'label' => 'Google Gemini',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'json' => 'prompt',
            'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        ],

        'groq' => [
            'label' => 'Groq',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'json' => 'object',
            'timeout' => (int) env('GROQ_TIMEOUT', 30),
        ],

        'openrouter' => [
            'label' => 'OpenRouter',
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY'),
            // Free models end in ":free"; they change often, so check openrouter.ai/models.
            'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'json' => 'prompt', // free models differ in what they support
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 45),
        ],

        'ollama' => [
            'label' => 'Ollama (self-hosted)',
            'base_url' => env('OLLAMA_BASE_URL'), // e.g. http://127.0.0.1:11434/v1
            'keyless' => true,
            'model' => env('OLLAMA_MODEL', 'llama3.1'),
            'json' => 'object',
            'timeout' => (int) env('OLLAMA_TIMEOUT', 90),
        ],

        'openai' => [
            'label' => 'OpenAI',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'model' => env('OPENAI_CLASSIFICATION_MODEL', 'gpt-4.1-mini'),
            'json' => 'schema',
            'dialect' => 'openai', // sends OpenAI-only options (store=false, max_completion_tokens)
            'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        ],

    ],

    // How long (seconds) a model rests after a problem before it is tried again.
    'cooldown' => [
        'rate_limited' => 60,  // doubled on each repeat within an hour, up to "max"
        'unavailable' => 30,   // timeouts and server errors
        'rejected' => 3600,    // wrong key, or the model doesn't exist
        'max' => 3600,
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

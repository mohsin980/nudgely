<?php

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| The single source of plan definitions. Nothing else in the application
| hard-codes prices or limits: read them through App\Billing\PlanCatalog.
|
| provider: which BillingProviderInterface implementation is used: "stripe"
| (hosted checkout, billing portal) or "manual" (local, no payments: for
| development, internal accounts and tests).
| default_plan: what an organization without a subscription gets.
|
| Limits: an integer, or null for "unlimited". Monthly limits follow the
| billing period (the calendar month without a subscription).
|
*/

return [

    'provider' => env('BILLING_PROVIDER', 'manual'),

    'default_plan' => 'free',

    // Free trial for a business's first paid subscription (never repeated).
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    // The owner is reminded this many days before the sign-up trial ends.
    'trial_reminder_days' => (int) env('BILLING_TRIAL_REMINDER_DAYS', 3),

    // New businesses start a card-free trial of this plan at sign-up; afterwards they choose a plan or use Free.
    'signup_trial_plan' => env('BILLING_SIGNUP_TRIAL_PLAN', 'starter'),

    // A failed payment keeps the plan for this many days while the provider retries and the owner
    // fixes the card. After that, paid-only limits stop applying (Free limits; nothing is deleted).
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // The admin usage monitor warns when an organization has used at least this percent of a plan limit.
    'usage_warning_percent' => (int) env('BILLING_USAGE_WARNING_PERCENT', 80),

    // A stored subscription that the provider has not confirmed for this many minutes is shown as stale to
    // administrators (webhooks and the hourly sync normally keep it much fresher).
    'stale_after_minutes' => (int) env('BILLING_STALE_AFTER_MINUTES', 180),

    // The "trial ending soon" banner appears this many days before a trial ends.
    'trial_banner_days' => (int) env('BILLING_TRIAL_BANNER_DAYS', 7),

    // The "trial/subscription ended" banner stays this many days.
    'ended_banner_days' => (int) env('BILLING_ENDED_BANNER_DAYS', 30),

    // Prices are shown and charged in one currency per business: PKR for visitors from Pakistan, USD elsewhere.
    // The currency is decided from the visitor's country (see BillingCurrency) and pinned for their session.
    'currencies' => [
        'default' => 'USD',
        'supported' => ['USD', 'PKR'],
        'by_country' => ['PK' => 'PKR'],
    ],

    // Where the visitor's country comes from. Only enable a header when a CDN in front of the app sets it and
    // strips any copy sent by the visitor: Cloudflare (CF-IPCountry) or CloudFront (CloudFront-Viewer-Country).
    // Without one, every visitor gets the default currency.
    'geo' => [
        'country_headers' => array_values(array_filter(explode(',', (string) env('BILLING_COUNTRY_HEADERS', '')))),
    ],

    // Plan limits are enforced where things are created. A switch (not a per-plan setting) so a
    // rollout can start with it off; it stays on in production. Tests turn it off unless testing limits.
    'enforce_limits' => (bool) env('BILLING_ENFORCE_LIMITS', true),

    // What each feature key is called on the plan pages.
    'feature_labels' => [
        'estimates' => 'Estimates',
        'follow_ups' => 'Automatic follow-ups',
        'automations' => 'Automations',
        'ai_reply_classification' => 'AI reply sorting',
        'ai_assistant' => 'AI WhatsApp assistant',
        'team_roles' => 'Team roles & permissions',
        'custom_branding' => 'Custom branding',
        'priority_support' => 'Priority support',
    ],

    'plans' => [

        'free' => [
            'name' => 'Free',
            'price_cents' => 0,
            'interval' => 'month',
            'provider_price_id' => null,
            'prices' => ['PKR' => 0],
            'provider_price_ids' => [],
            'limits' => [
                'customers' => 100,
                'automations' => 3,
                'team_members' => 1,
                'outbound_emails' => 500,
                'estimates' => 50,
                'ai_conversations' => 0,
            ],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification'],
        ],

        'starter' => [
            'name' => 'Starter',
            'price_cents' => 2900,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_STARTER'),
            // Minor units (paisa): PKR 8,000.
            'prices' => ['PKR' => 8000 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_STARTER_PKR')],
            'limits' => [
                'customers' => 500,
                'automations' => 15,
                'team_members' => 3,
                'outbound_emails' => 2500,
                'estimates' => 250,
                'ai_conversations' => 100,
            ],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification', 'ai_assistant', 'team_roles', 'custom_branding'],
        ],

        'pro' => [
            'name' => 'Pro',
            'price_cents' => 7900,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_PRO'),
            // Minor units (paisa): PKR 22,000.
            'prices' => ['PKR' => 22000 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_PRO_PKR')],
            'limits' => [
                'customers' => 2000,
                'automations' => 50,
                'team_members' => 10,
                'outbound_emails' => 10000,
                'estimates' => 1000,
                'ai_conversations' => 300,
            ],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification', 'ai_assistant', 'team_roles', 'custom_branding', 'priority_support'],
        ],

    ],

    /*
    | Add-ons: optional extras bought on top of a plan. They never replace the plan.
    | - applies_to: the plan keys the add-on can be bought with.
    | - limits: amounts ADDED to the plan's limit (not replacing it), for the limit keys listed in plans.*.limits.
    | - features: features switched on for the business while the add-on is active.
    | Prices are USD per month. Each add-on needs its own Stripe price (STRIPE_PRICE_ADDON_*).
    */
    'addons' => [

        'ai_assistant' => [
            'name' => 'AI WhatsApp assistant',
            'description' => 'Your AI assistant answers WhatsApp leads, qualifies them and hands over to your team. Includes 100 AI conversations a month.',
            'price_cents' => 1900,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_ADDON_AI_ASSISTANT'),
            'prices' => ['PKR' => 5000 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_ADDON_AI_ASSISTANT_PKR')],
            'applies_to' => ['free'],
            'features' => ['ai_assistant'],
            'limits' => ['ai_conversations' => 100],
        ],

        'ai_conversations' => [
            'name' => '+200 AI conversations a month',
            'description' => 'More AI assistant conversations each month, for busy businesses.',
            'price_cents' => 1200,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_ADDON_AI_CONVERSATIONS'),
            'prices' => ['PKR' => 3500 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_ADDON_AI_CONVERSATIONS_PKR')],
            'applies_to' => ['starter', 'pro'],
            'features' => [],
            'limits' => ['ai_conversations' => 200],
        ],

        'customers' => [
            'name' => '+1,000 customers',
            'description' => 'Room for 1,000 more customers.',
            'price_cents' => 1000,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_ADDON_CUSTOMERS'),
            'prices' => ['PKR' => 2800 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_ADDON_CUSTOMERS_PKR')],
            'applies_to' => ['free', 'starter', 'pro'],
            'features' => [],
            'limits' => ['customers' => 1000],
        ],

        'team_members' => [
            'name' => '+5 team members',
            'description' => 'Five more people on your team, each with their own role.',
            'price_cents' => 1000,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_ADDON_TEAM_MEMBERS'),
            'prices' => ['PKR' => 2800 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_ADDON_TEAM_MEMBERS_PKR')],
            'applies_to' => ['free', 'starter', 'pro'],
            'features' => [],
            'limits' => ['team_members' => 5],
        ],

        'outbound_emails' => [
            'name' => '+10,000 emails a month',
            'description' => 'Ten thousand more outbound emails each month.',
            'price_cents' => 800,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_ADDON_OUTBOUND_EMAILS'),
            'prices' => ['PKR' => 2200 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_ADDON_OUTBOUND_EMAILS_PKR')],
            'applies_to' => ['free', 'starter', 'pro'],
            'features' => [],
            'limits' => ['outbound_emails' => 10000],
        ],

        'custom_branding' => [
            'name' => 'Custom branding',
            'description' => 'Your logo and colours on emails and estimates. Already included in Starter and Pro.',
            'price_cents' => 900,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_ADDON_CUSTOM_BRANDING'),
            'prices' => ['PKR' => 2500 * 100],
            'provider_price_ids' => ['PKR' => env('STRIPE_PRICE_ADDON_CUSTOM_BRANDING_PKR')],
            'applies_to' => ['free'],
            'features' => ['custom_branding'],
            'limits' => [],
        ],

    ],

];

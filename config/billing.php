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

    // The "trial ending soon" banner appears this many days before a trial ends.
    'trial_banner_days' => (int) env('BILLING_TRIAL_BANNER_DAYS', 7),

    // The "trial/subscription ended" banner stays this many days.
    'ended_banner_days' => (int) env('BILLING_ENDED_BANNER_DAYS', 30),

    'currency' => 'USD',

    // Plan limits are enforced where things are created. A switch (not a per-plan setting) so a
    // rollout can start with it off; it stays on in production. Tests turn it off unless testing limits.
    'enforce_limits' => (bool) env('BILLING_ENFORCE_LIMITS', true),

    // What each feature key is called on the plan pages.
    'feature_labels' => [
        'estimates' => 'Estimates',
        'follow_ups' => 'Automatic follow-ups',
        'automations' => 'Automations',
        'ai_reply_classification' => 'AI reply sorting',
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
            'limits' => [
                'customers' => 100,
                'automations' => 3,
                'team_members' => 1,
                'outbound_emails' => 500,
                'estimates' => 50,
            ],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification'],
        ],

        'starter' => [
            'name' => 'Starter',
            'price_cents' => 2900,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_STARTER'),
            'limits' => [
                'customers' => 500,
                'automations' => 15,
                'team_members' => 3,
                'outbound_emails' => 2500,
                'estimates' => 250,
            ],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification', 'team_roles', 'custom_branding'],
        ],

        'pro' => [
            'name' => 'Pro',
            'price_cents' => 7900,
            'interval' => 'month',
            'provider_price_id' => env('STRIPE_PRICE_PRO'),
            'limits' => [
                'customers' => 2000,
                'automations' => 50,
                'team_members' => 10,
                'outbound_emails' => 10000,
                'estimates' => 1000,
            ],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification', 'team_roles', 'custom_branding', 'priority_support'],
        ],

    ],

];

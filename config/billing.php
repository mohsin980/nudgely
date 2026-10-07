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
| Limits: an integer, or null for "unlimited". Monthly limits reset at the
| start of each calendar month in the organization's timezone.
|
*/

return [

    'provider' => env('BILLING_PROVIDER', 'manual'),

    'default_plan' => 'free',

    // Free trial for a business's first paid subscription (never repeated).
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    // New businesses start a card-free trial of this plan at sign-up; afterwards they choose a plan or use Free.
    'signup_trial_plan' => env('BILLING_SIGNUP_TRIAL_PLAN', 'starter'),

    'currency' => 'USD',

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

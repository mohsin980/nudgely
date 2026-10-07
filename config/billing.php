<?php

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| The single source of plan definitions. Nothing else in the application
| hard-codes prices or limits: read them through App\Billing\PlanCatalog.
|
| provider: which BillingProviderInterface implementation is used ("manual"
| keeps subscriptions locally until a payment provider is connected).
| default_plan: what an organization without a subscription gets.
|
| Limits: an integer, or null for "unlimited". Monthly limits reset at the
| start of each calendar month in the organization's timezone.
|
*/

return [

    'provider' => env('BILLING_PROVIDER', 'manual'),

    'default_plan' => 'free',

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
            'provider_price_id' => env('BILLING_STARTER_PRICE_ID'),
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
            'provider_price_id' => env('BILLING_PRO_PRICE_ID'),
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

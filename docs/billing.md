# Billing foundation (Task 14A)

No payments yet: this is the internal architecture the rest of the app talks to.

## Plans — `config/billing.php` (the only place plan values live)

| | Free | Starter | Pro |
| --- | --: | --: | --: |
| Price / month | $0 | $29 | $79 |
| Customers | 100 | 500 | 2,000 |
| Automations | 3 | 15 | 50 |
| Team members | 1 | 3 | 10 |
| Outbound emails / month | 500 | 2,500 | 10,000 |
| Estimates / month | 50 | 250 | 1,000 |

Each plan: key, name, `price_cents`, `interval`, `limits` (int, or null = unlimited), `features`, `provider_price_id` (env, for Stripe later). `PlanCatalog` loads and validates them once (a missing limit, bad price/interval or unknown default plan throws); read plans only through it (`App\Billing\Plan`).

## Pieces

- `Subscription` model (`subscriptions` table): organization, provider, provider subscription ID, plan key, `SubscriptionStatus` (trialing, active, past_due, paused, cancelled, incomplete, unpaid, expired), trial/period dates, cancel-at-period-end, canceled/ended times. At most one current (not cancelled/expired) subscription per organization (partial unique index). `Organization::subscriptions()` / `currentSubscription()`; `organizations.billing_provider` + `billing_customer_id` hold the provider customer.
- `BillingProviderInterface` (`createCustomer`, `createSubscription`, `changePlan`, `cancel`, `resume`) returning provider-neutral `ProviderSubscription` data. `BillingProviderManager` resolves `config('billing.provider')`; the `manual` provider keeps subscriptions locally (no payments) until Stripe is added as another driver.
- `BillingService`: the only caller of the provider. `subscribe`, `changePlan`, `cancel` (now or at period end), `resume` for the actor's own organization (manage-billing permission, transaction, audit entry); `sync()` records provider updates idempotently and refuses to move a subscription between organizations (for webhooks later).
- `EntitlementService`: the plan in force — a subscription that grants access (trialing within the trial, active, past due; not after a scheduled cancellation reaches the period end), otherwise the default free plan — plus `limit()`, `allows($org, $key, $amount)`, `remaining()`, `hasFeature()`, `summary()`.
- `UsageService`: usage counted from the records (customers, non-archived automations, non-removed team members, outbound emails and new estimates this calendar month in the business's timezone).

## Access

`Permission::ManageBilling` (owner only) — Gate `manage-billing`, `SubscriptionPolicy`, and `BillingService` checks. Read-only page `/settings/billing`: plan, status, usage vs limits, plan comparison.

## Not yet

Stripe checkout/payments/webhooks, plan changes in the UI, limit enforcement in the features (the services are ready: `EntitlementService::allows()`), expiring ended subscriptions on a schedule.

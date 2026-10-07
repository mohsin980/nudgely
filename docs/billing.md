# Billing (Tasks 14A–14B)

The internal billing architecture (14A) connected to Stripe (14B). The rest of the app only talks to `BillingService` and `EntitlementService`.

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

## Stripe (Task 14B)

Configuration (environment only, never committed): `BILLING_PROVIDER=stripe`, `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (for the coming webhooks), `STRIPE_PRICE_STARTER`, `STRIPE_PRICE_PRO`. `StripeBillingProvider` calls the Stripe REST API with Laravel's HTTP client (no SDK); every failure becomes a `BillingException` with a safe message and is logged without secrets or object IDs. QuoteFollow never sees or stores card data: customers pay on Stripe Checkout and manage cards, billing details and invoices in the Stripe billing portal.

| Change | How |
| --- | --- |
| Free → Starter / Pro | `startCheckout()` → Stripe hosted Checkout (subscription mode, plan price, `client_reference_id` + metadata = organization) → back to `/settings/billing?checkout=success&session_id=…` → `completeCheckout()` verifies the session belongs to this organization and its Stripe customer, then records the subscription (idempotent) |
| Trial | 14 days (`billing.trial_days`) on a business's first paid subscription only (`organizations.trial_used_at`, or any earlier trial) |
| Starter → Pro (upgrade) | price switched now with `proration_behavior=create_prorations` |
| Pro → Starter (downgrade) | Stripe subscription schedule: current price until the period end, then Starter. Locally `scheduled_plan` / `scheduled_change_at`; the plan stays Pro until then. Choosing Pro again releases the schedule |
| Starter / Pro → Free | cancel at period end |
| Cancel | default at period end ("Your subscription will remain active until …"); immediate cancel is available to code |
| Resume | undoes a cancellation scheduled for the period end |
| Billing portal | `portalUrl()` → Stripe billing portal session, returning to the billing page (which re-syncs) |
| Synchronization | `refresh()` after returning from Stripe, and `billing:sync-subscriptions` hourly (until webhooks) |

Downgrades never delete data: if usage is above the new plan's limits the billing page says so; existing records stay, and (once enforcement is added) new ones are limited.

The `manual` provider implements the same interface locally (checkout completes immediately, scheduled changes apply when the sync runs after their time).

## Not yet

Stripe webhooks (status changes between syncs, failed payments), limit enforcement in the features (`EntitlementService::allows()` is ready), annual plans.

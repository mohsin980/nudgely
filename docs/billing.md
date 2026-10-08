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
- `UsageService`: usage counted from the records (non-deleted customers, active automations, active team members; outbound emails (queued, sending, sent; failed ones are free) and new estimates in the billing period).

## Access

`Permission::ManageBilling` (owner only) — Gate `manage-billing`, `SubscriptionPolicy`, and `BillingService` checks. Billing pages (owner only, `can:manage-billing`): `/settings/billing` (current plan, status, billing period, next billing date, cancellation/resume, saved card as brand + last four), `/settings/billing/plans` (plans from `PlanCatalog`, upgrade/downgrade), `/settings/billing/usage` (used / limit with progress bars), `/settings/billing/history` (invoices with links to the provider's hosted invoice page). Actions call `BillingService`; the views hold no plan data or billing rules (feature names come from `billing.feature_labels`). Card brand/last four and invoices are read through `BillingProviderInterface::paymentMethod()` / `invoices()` and cached for 5 minutes. An "Upgrade Plan" link follows a limit message only for people who can manage billing.

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

## Sign-up trial (card-free)

A new business starts a 14-day trial of the Starter plan (`billing.trial_days`, `billing.signup_trial_plan`) at sign-up, inside QuoteFollow: no card, no subscription, no Stripe call (`organizations.trial_ends_at`, `trial_used_at`). `EntitlementService::plan()` resolves: a subscription that grants access, else the running sign-up trial, else Free; `trial()` reports days left (the billing page and an owner banner show it). When it ends the business is on Free; nothing is deleted. The trial is once per business, so a later Stripe checkout adds no further trial and is charged from the start; a subscription ends the sign-up trial. Stripe's own trial (`trial_period_days`) only applies to subscriptions created by internal/manual means or businesses that never had a trial (e.g. created before this feature).

## Trial reminder

`billing:send-trial-reminders` (daily 09:00) tells the owner, in the app (and by email if they chose that), `billing.trial_reminder_days` (3) days before a card-free trial ends — once per trial, and not for businesses that already subscribed.

## Stripe webhooks

`POST /webhooks/stripe` (set `STRIPE_WEBHOOK_SECRET`; subscribe the endpoint to `checkout.session.completed`, `customer.subscription.created/updated/deleted` and `invoice.paid` / `invoice.payment_failed`). Browser requests never decide subscription state: only a verified webhook (or a server-side read from Stripe) does. The `Stripe-Signature` header is verified (HMAC-SHA256 of `timestamp.rawBody`, 5-minute tolerance, constant-time compare): bad signature → 400, no secret → 503.

**Event record** (`billing_webhook_events`): `provider`, `provider_event_id` (unique with provider), `event_type`, `status` (`received` → `processing` → `processed` / `ignored` / `failed`), `organization_id`, `attempts`, `processed_at`, `failed_at`, `detail` (ignore reason or exception class) and `metadata` (customer/subscription ids, object status, livemode, API version). The payload itself, amounts, emails and payment details are never stored.

**Idempotency**: the event row is unique per provider event ID, and a single conditional `UPDATE` claims it (`received`/`failed` → `processing`), so simultaneous or repeated deliveries run it once and later ones are acknowledged as `duplicate`. A claim abandoned for 5 minutes (crashed worker) can be taken over; a failed event is retried by Stripe. Syncing itself is idempotent too.

**Normalization**: an event only says *which* customer/subscription changed; `BillingService::syncFromProvider()` re-reads it from Stripe, so repeated or out-of-order events can't leave stale data. Stripe `active`, `trialing`, `past_due`, `canceled`, `unpaid`, `incomplete` (also `paused`, `incomplete_expired`) map to QuoteFollow `SubscriptionStatus` values. A failed payment therefore turns the subscription `past_due` (and alerts the owner once per event); `invoice.paid` restores `active`. Each status change is written to the activity log (`subscription_status_changed`, with from/to). A subscription can't be claimed by another organization; unknown customers and unhandled event types are acknowledged and recorded as `ignored`. The hourly `billing:sync-subscriptions` stays as a safety net.

## Limit enforcement

**Usage** is counted from the records. Monthly limits (emails, estimates) follow the **billing period**: the subscription's current period (rolled forward by whole months if Stripe hasn't reported a renewal yet); with no subscription the calendar month in the business's timezone.

**Asking**: `EntitlementService::canCreateCustomer / canAddTeamMember / canCreateAutomation / canSendEmail / canCreateEstimate($org)` answer yes/no (no checks in Blade). **Enforcing** is server-side: `EntitlementService::guard()` runs the check and the creation in one transaction and throws `PlanLimitException` (message, `key`, `limit`, `upgradePlan`, `upgradeUrl()`). Only *adding* is refused: nothing existing is deleted when a plan shrinks.

| Limit | Enforced in |
| --- | --- |
| Customers | `CustomerService::create` (form shows the message) |
| New estimates / period | `EstimateService::create` (revisions don't count) |
| Active automations | `AutomationBuilder::activate` (also resuming a paused one); drafts, paused and archived ones don't count |
| Team members | `InvitationService::invite` (open invitations hold a seat), `accept`, and `TeamService::reactivate` |
| Outbound emails / period | `EmailService` for customer-facing email → the send fails with the message; team invitations and notices are never blocked |

**Concurrency**: `guard()` takes a row lock on the organization (`SELECT … FOR UPDATE`) before counting, and holds it until the creation commits, so simultaneous requests for the same organization take turns (99/100 → exactly one succeeds). Other organizations are unaffected. Verified with 4 simultaneous processes. Inbound customer replies are never blocked. `BILLING_ENFORCE_LIMITS=false` turns enforcement off (the test suite does, except in the limit tests).

## Not yet

Annual plans; email notices when a limit is nearly reached.

# Subscriptions and billing (SA-08)

`/admin/subscriptions` lists subscriptions and shows their details, provider-reported invoices and webhook activity. It is read-only apart from one action: refresh a subscription from Stripe. There is no cancel, refund, plan change, checkout or payment entry here.

## Source of truth

**Stripe owns subscription state and payments.** QuoteFlow stores a copy of each subscription (`subscriptions`) that webhooks and an hourly sync keep current. It does **not** store payments or invoices (and never card numbers or security codes), so there is no local payment record that can disagree with Stripe or be duplicated.

| Data | Where it comes from |
| --- | --- |
| Status, plan, period, trial and cancellation dates | Stored copy, written only from what Stripe reports (`BillingService::sync` / `apply`) |
| Billing interval | The plan's configured interval (`config/billing.php`), not a stored column |
| Invoices and payment status/amount/currency/reference | Read **live** from Stripe on request, cached 5 minutes, never stored |
| Webhook activity | `billing_webhook_events`: event type, processing result, attempts; never the payload |

## Missing, stale and confirmed data

Every subscription shows "Provider data":

| State | Meaning |
| --- | --- |
| Confirmed | Stripe confirmed it within `billing.stale_after_minutes` (default 180), or the subscription has ended |
| Stale | Not confirmed for longer than that while the subscription is still current: it may differ from Stripe |
| Not yet confirmed | Stripe never confirmed it (rows from before sync times were recorded stay here until their next sync) |
| Local only | Created by the manual provider (development/internal); there is no external provider |

`last_synced_at` is set whenever the subscription is created or updated from the provider (webhook, hourly sync, administrator refresh). If Stripe cannot be reached when invoices are requested, the page says so rather than showing an empty history.

## Statuses

Active, Trialing, Past due, Unpaid, Incomplete (payment pending), Paused, Cancelled and Expired are shown as the stored provider status. A browser redirect from checkout never marks anything active: a subscription is recorded only from Stripe's own data (verified checkout lookup or signed webhook).

## Webhook safety (existing behaviour, covered by tests)

Signatures are verified on the raw body; every event is recorded once by its provider event ID (`provider` + `provider_event_id` unique); concurrent deliveries are claimed atomically; and a webhook only says *which* subscription changed, the state itself is re-read from Stripe, so repeated or out-of-order events cannot leave wrong data.

## Who can do what

| Action | Permission | Support | Billing | Super |
| --- | --- | --- | --- | --- |
| List and open subscriptions | `view_subscriptions` | yes | yes | yes |
| Invoices (live from Stripe), webhook activity | `view_payments` | no | yes | yes |
| Refresh a subscription from Stripe | `manage_subscriptions` | no | no | yes |

Refresh re-reads one subscription through the existing `BillingService` (same code as the scheduled sync), shows a confirmation, is rate-limited to once per 30 seconds per subscription, and is audited (actor, subscription, what changed). Because it uses the normal sync path, any owner notifications or lifecycle records for a real status change are created exactly as for a webhook. It is refused for `manual` subscriptions. If Stripe fails, the stored record is left unchanged and nothing is audited.

Access is decided by platform permissions; the customer-side `SubscriptionPolicy` (an owner's own billing) is unchanged and does not apply here.

## Not included

Refunds, cancellations, plan changes, payment records, a payment history table, and any second provider. Collected-revenue reporting still needs payment records stored from Stripe (see admin-dashboard.md).

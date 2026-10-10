# Super Admin dashboard (SA-04)

The `/admin` landing page shows platform-wide numbers. Every figure is calculated from persisted data; nothing is sample data. Each widget is shown only to administrators who hold its permission (see [admin-security.md](admin-security.md)).

| Widget | Permission | Source |
| --- | --- | --- |
| Customers (organizations) | `view_organizations` | `organizations`, `users.last_active_at` |
| Users | `view_users` | `users` |
| Subscriptions | `view_subscriptions` | `subscriptions` |
| Revenue | `view_payments` | `subscriptions` + plan prices in `config/billing.php` |
| Recent platform activity | `view_audit_logs` | `organization_activity` |

Support admins see everything except revenue; billing admins see customers, subscriptions and revenue but not users or activity; super admins see all.

## Metric definitions

**Organizations**: all rows in `organizations`. *New* = created in the last 30 days, compared with the 30 days before.

**Active organizations**: an organization with at least one *active* member (not suspended or removed) whose `last_active_at` is within the last 30 days. `last_active_at` is refreshed at most every 5 minutes while someone uses the app. Suspending an organization is not built yet; there is no organization-level status.

**Registered users**: all rows in `users` (includes platform staff). *Active accounts*: status `active`.

**Subscriptions** (rows in `subscriptions`, by status):

- *Active*: status `active` only. Trialing, past due, paused, incomplete, unpaid, cancelled and expired are never counted as active.
- *Trialing*: status `trialing` (paid-plan trials). Card-free sign-up trials live on the organization, not as subscription rows, so they are not counted here.
- *Past due*: status `past_due`; shown as a note next to Active, not added to it.
- *Canceled*: status `cancelled`. The comparison uses `canceled_at`: last 30 days vs the 30 before.
- Subscriptions from the `manual` provider (internal/dev, no payments) are included in the counts because they are real subscription records.

**MRR (calculated locally)**: sum of the monthly list price of subscriptions that are `active` or `past_due`, whose provider is a real payment provider (not `manual`). Price comes from the plan in `config/billing.php`; yearly plans count 1/12.

| Situation | Effect on MRR |
| --- | --- |
| Trial (`trialing`) | Excluded until it converts to `active` |
| Discounts / coupons | **Not reflected**: list price is used (MRR is overstated if discounts are used) |
| Annual billing | Price / 12 |
| Refunds | Not reflected |
| Failed payment (`past_due`) | Still counted, and the amount is shown as "past due" because it is at risk; excluded once it becomes `unpaid`/`cancelled` |
| Paused, incomplete, unpaid, cancelled, expired | Excluded |
| Plan removed from config | Excluded, with a count shown |
| Billing provider `manual` and no real subscriptions | "Not available" |

MRR is a recurring-run-rate estimate. It is **not** lifetime revenue, payment volume or cash collected, and it is not interchangeable with them.

**Revenue reported by Stripe**: shown as "Not configured". The application does not store payments or invoices yet (invoices are fetched live per organization), so collected revenue, refunds and failed-payment totals cannot be reported without inventing data. When payment records are stored, add that widget next to MRR and keep the two labelled separately.

**Recent platform activity**: the latest 10 rows of `organization_activity` (paged), newest first. Only time, organization name and the event label are shown, never the change details or any customer message content.

## Behaviour

- Widgets load lazily and in parallel; each uses one or two aggregate queries (conditional counts and `GROUP BY`), none per organization. A test fails if query count grows with data.
- A failing metric shows an "Unavailable" card instead of breaking the page, and the error is reported.
- Empty database: counts show 0, MRR shows $0.00 (or "Not available" in manual mode), activity shows an empty state.

## Navigation

Links to organization, subscription and usage pages are the panel's sidebar items. Those modules do not exist yet; when a resource or page is added it uses `RequiresPlatformPermission`, so it appears in the sidebar only for administrators allowed to use it.

# Usage and quota monitoring (SA-09)

`/admin/usage` shows, for every organization, how much of each plan limit it has used. It is read-only and permission `view_usage` (support, billing and super admins). It reports the numbers the backend enforces with; it is not a separate analytics system.

## Where each number comes from

All figures come from `UsageService`, the same definitions the enforcing services use; `usageForMany()` computes them for many organizations at once and a test keeps it identical to the per-organization `usage()`.

| Measure | Source (persisted rows) | Counted | Limit enforced in |
| --- | --- | --- | --- |
| Customers | `customers`, demo/sample customers excluded, soft-deleted excluded | right now | `CustomerService::create` |
| Automations | `automations` with status `active` | right now | `AutomationBuilder` (activation) |
| Team members | `users` with status `active` | right now | `InvitationService`, `TeamService::reactivate`, `UserAccountService::reactivate` |
| Outbound emails | `messages`: outbound, status queued/sending/sent/delivered/bounced/complained | inside the plan period | `EmailService` (when queuing customer email) |
| Estimates | `estimates` with `revision = 1` (revisions are not new estimates) | inside the plan period | `EstimateService::create` |

`EntitlementService::guard()` runs the check and the insert under the organization's lock, so concurrent requests cannot overshoot, and refuses when `usage + 1 > limit` (that is, at or above the limit).

## Periods and time zones

- With a subscription that grants access: the subscription's current billing period (rolled forward by whole months if the provider has not reported the renewal yet).
- Otherwise: the **calendar month in the organization's own time zone** (default `config('follow_ups.default_timezone')`).
- Periods are `[start, end)` stored in UTC: a record at exactly the start counts, one at exactly the end belongs to the next period. The list shows the period end in the organization's time zone; the details page shows both ends and the zone.

## Current period versus lifetime

The list is **current period** only (customers, automations and seats are "as they stand now"). The details page (`/admin/usage/{id}`) adds, for emails, failed sends, new estimates and completed follow-ups: this plan period, last 30 days and lifetime. Rules:

- **Not estimated:** past customer, automation and seat counts are not recorded (rows are deleted or change status), so no history is shown for them.
- **Opportunities created:** unavailable, the application has no such record.
- "Follow-ups completed" counts follow-ups with status completed and a completion time; it includes manual completions, so it is not labelled "sent".

## Duplicates, retries and failures

Emails are counted by message **row**, not by attempt or provider event: a retried send (`send_attempts` > 1) is one message, and repeated or duplicate provider events only move that row's status, never add rows. Failed sends are not counted against the plan (shown separately on the details page). Inbound messages are never counted. Tests cover all three.

## Quota states

| State | Meaning |
| --- | --- |
| OK | Below `billing.usage_warning_percent` (default 80%) |
| Nearing limit | At or above that percent, below the limit |
| At limit | Used equals the limit: the backend refuses one more |
| Over limit | Used exceeds the limit (for example after a plan's limit was lowered); nothing is deleted, new items are refused |
| Unlimited | No limit |

An organization's overall state is its worst measure. A test checks "at limit" is true exactly when `EntitlementService::allows()` is false.

## Filters and performance

- Search: name (contains) or exact ID. Sort: name, ID, created. Pages of 25/50/100; only the displayed page is measured, in a fixed number of queries (grouped counts; one OR-group per organization for period-based counts, 100 organizations per query).
- Plan and quota-status filters depend on computed usage (periods differ per organization), so they measure organizations in batches of 200 and list the matches, worst first. Cost grows linearly with the number of organizations (about a dozen queries per 200). If the platform grows into many thousands of organizations, precompute states on a schedule instead.
- New indexes: `messages (organization_id, direction, created_at)`, `estimates (organization_id, revision, created_at)`, `follow_ups (organization_id, status, completed_at)`.

## Platform capacity

A collapsed panel shows counts from the existing operations report: jobs waiting, failed jobs, emails stuck sending, follow-ups stranded, stalled automation runs, and outbound emails of the last 24 hours by status. Counts only; job payloads and message content are never shown. Cached for 60 seconds.

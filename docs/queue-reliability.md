# Queue, scheduler and automation reliability (Task 16B)

## Principles
- **Every job is safe to run twice, late, or concurrently.** Business effects are guarded by an atomic claim
  (conditional `UPDATE`, or `SELECT … FOR UPDATE`) or a unique key, never by a check alone.
- **Jobs carry IDs, not models.** Each job re-reads its row and re-checks eligibility before acting. The
  organization is always taken from the row, never from the payload.
- **No database transaction is open while calling an external API.** Email, Stripe and AI calls run outside
  transactions; state is claimed first and the result is persisted afterwards.
- **Ambiguous outcomes are never resent.** If a provider may already have accepted an email, it is recorded
  as failed (`outcome_unknown`) rather than retried.

## Timing invariant (queue config)
`job timeout < automation stale window < queue retry_after`

| Setting | Value | Why |
|---|---|---|
| `queue.connections.database.retry_after` | 300s (was 90s) | A job still running when redelivered runs twice. 90s was shorter than the 120s action timeout. |
| Job timeouts | 60–240s | Every queued job declares one; `JobAndSchedulerConfigurationTest` enforces `< retry_after`. |
| `automation.retries.stale_after_seconds` | 240s (was 600s) | A crashed step is reclaimed on its first redelivery (≈300s), never a live one (≤120s). |

Run workers with `--timeout` below `retry_after`, e.g. `php artisan queue:work --timeout=120 --tries=0`
(the per-job values above take precedence).

## Job inventory

| Job | Purpose | Queue | Delay | Tries | Backoff (s) | Timeout | Uniqueness / idempotency | Failure handling | Resource resolution |
|---|---|---|---|---|---|---|---|---|---|
| `SendEmailJob` | Send one queued email | default | — | 5 | 30, 120, 600, 1800 | 60 | `ShouldBeUnique` per message; atomic claim `queued→sending`; automation/follow-up keys (unique index) | Transient → back to queued, retried. Permanent → failed. Unknown/timeout → failed, **not resent**. `failed()` marks an unsent message failed | Message by ID; org from the message |
| `ProcessInboundEmailJob` | Process a stored inbound webhook | default | — | 5 | 10, 60, 300, 900 | 120 | `ShouldBeUnique` per webhook event; row lock; `processed_at` | `failed()` records failure, logs class only | Webhook event by ID |
| `ProcessFollowUpJob` | Process one due follow-up | automation | scheduled | 3 | 30, 120 | 120 | Row lock; status and `due_notified_at` checks; stranded re-queue (below) | `failAfterRetries` marks the follow-up failed | Follow-up by ID; customer/conversation/automation re-checked |
| `EvaluateAutomationJob` | Match one event to automations | automation | — | 3 | 10, 60 | 120 | Unique `(organization, automation, event type, event ID)` on runs | Duplicate-safe; no side effect of its own | Event carries IDs; org and records verified |
| `ExecuteAutomationActionJob` | Run one automation step | automation | — | 3 (config) | 10, 60 | 120 | Atomic claim of `pending`, or `running` once stale; per-step keys on tasks, follow-ups, emails | Temporary → released and retried; `failed()` records failure | Action run by ID; org mismatch rejected |
| `ResumeAutomationRunJob` | Continue a run after its WAIT | automation | at `resume_at` | 3 | 10, 60 | 120 | Claim inside one transaction under `FOR UPDATE`; lease on `resume_at` | Skipped/failed runs record why | Run by ID |
| `ClassifyCustomerReplyJob` | AI classification of a reply | default | — | 4 | 30, 120, 600 | 90 | `ShouldBeUnique` per request ID | Transient retried; invalid output recorded | Message by ID |
| `Billing\NotifyOwnerJob` | Tell the owner about billing | default | — | 3 | 30, 120 | 60 | `TeamNotifier` notifies once per key | Retried | Org by ID; owner resolved at run time |
| `Billing\ExpireTrialsJob` | End trials (hourly) | default | scheduled | 3 | 60, 300 | 240 | `ShouldBeUnique`; row claim sets `trial_expired_at` | Next hour picks up | Org rows |
| `Billing\CheckGracePeriodsJob` | Restrict past-due subscriptions (hourly) | default | scheduled | 3 | 60, 300 | 240 | `ShouldBeUnique`; conditional update sets `restricted_at` | Provider failures counted, not fatal | Subscription rows |

Usage counts are read from message rows (`queued`, `sending`, `sent`), so a retry never double-counts.

## Scheduled tasks

Every task uses `withoutOverlapping()` and `onOneServer()` (cache lock, supported by the database cache).

| Command | Frequency | Purpose |
|---|---|---|
| `follow-ups:process-due` | every minute | Mark due follow-ups, queue them, re-queue stranded ones |
| `automations:resume-waiting` | every minute | Queue runs whose wait is over (lease 10 min) |
| `automations:recover-stalled` | every 15 min | Queue pending steps nothing is working on; complete finished runs |
| `email:recover-stuck-sends` | every 5 min | Mark emails left `sending` by a crash as failed, never resend |
| `follow-ups:notify-overdue` | hourly | Tell the team about overdue due follow-ups |
| `estimates:expire` | hourly | Expire estimates past "valid until" |
| `billing:sync-subscriptions` | hourly | Reconcile subscriptions with the provider |
| `billing-expire-trials` (job) | hourly at :05 | End expired trials |
| `billing-check-grace-periods` (job) | hourly at :35 | Restrict past-due subscriptions |
| `billing:send-trial-reminders` | daily 09:00 | Trial reminders (server time, see limitations) |
| `retention:prune` | daily 03:30 | Delete technical records past retention (below) |

## Recovery mechanisms
- **Stranded follow-ups:** `markDue` flips rows to `due` before dispatching. A due follow-up with no job for 15 minutes is queued again. Re-queuing is safe because `process()` locks the row and returns `already_processed` for a handled one.
- **Stuck emails:** `messages` left `sending` for 15 minutes are marked failed with "not resent". Their outcome is unknown, so they are never sent again automatically.
- **Stalled automation:** a pending step with a running run for 15 minutes is queued again. A run with no unfinished step is completed. Steps are claimed atomically, so re-queuing is safe.
- **Lost jobs in the queue:** Laravel redelivers reserved jobs after `retry_after`; the stale-claim rule above reclaims crashed steps.

## Idempotency keys
- Email: `messages.metadata->automation_key` (unique partial index) and the follow-up key `follow_up|{id}`.
- Tasks: `tasks.idempotency_key` per action and event. Follow-ups: `follow_ups.idempotency_key` (unique).
- Runs: unique `(organization_id, automation_id, event_type, event_id)`.
- Steps: unique `(automation_run_id, automation_action_id)`.
- Stripe and inbound email: `billing_webhook_events` and `webhook_events` unique on provider event ID.

## Retention (`retention:prune`)
Deletes only technical records, in batches of 500 (max 200 batches per table per run):
- processed webhook deliveries older than 90 days (`webhook_events`)
- processed or ignored billing webhook deliveries older than 180 days
- invitations that expired unaccepted more than 30 days ago
- reply addresses that expired more than 30 days ago

Customers, estimates, messages, follow-ups, automation runs and organizations are never pruned.

## Cascade fix
`follow_ups.estimate_id` was `ON DELETE SET NULL`. A follow-up whose estimate was deleted became a generic
"following up on your estimate" email. It is now `ON DELETE CASCADE` (migration `2026_10_25_000000`).

## Operations
- `php artisan reliability:report` shows counts by state (follow-ups, emails, runs, steps, queue) and failed jobs by class. It never prints payloads.
- `php artisan queue:failed` lists failed jobs; `php artisan queue:retry all` retries them after the cause is fixed.

## Known limitations and remaining risks
- An email whose send timed out is recorded as failed and not resent. The customer may need a manual resend. This was chosen over a possible duplicate.
- Provider 5xx and 429 responses are retried. A 5xx could in principle have been accepted; this is low risk and documented.
- Trial reminders run at 09:00 server time, not the business's local time. Moving them needs an hourly job that checks each business's local hour.
- Trial expiry claims the organization before notifying the owner. If the notification fails, the owner is not told, but the activity log records the expiry.
- `onOneServer` relies on the shared database cache. Multiple app servers must share one database.
- Sweeps process at most 200 rows per run. A large backlog drains over several runs.
- Business records (messages, automation runs) are kept indefinitely; no retention policy for them was defined.

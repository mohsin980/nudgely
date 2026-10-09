# Logging, error handling and monitoring (Task 16E)

Scope: how QuoteFlow records, redacts and surfaces what goes wrong. It builds on Laravel's logging, exception
handling, queue events and Context. Business behaviour (retries, idempotency, tenant rules) is unchanged.

## 1. Channels and configuration

- Log channels are defined in `config/logging.php`. Every channel carries the redaction tap
  (`App\Logging\RedactSensitiveLogs`), so no channel can write unredacted context.
- The default channel is unchanged (`LOG_CHANNEL`, default `stack` → `single`). See section 10 for production settings.
- `LOG_LEVEL` controls which events appear. Routine job starts and completions are `debug`; set `LOG_LEVEL=info`
  in production to keep them out.

## 2. Event names and levels

Every operational log carries an `event` key. Messages stay as the sentence they were, so existing tests and
searches keep working; filter on `event`.

| Event | Level | Written when |
|---|---|---|
| `exception.unhandled` | error | An unexpected exception reached the exception handler (once, scrubbed) |
| `job.queued` | debug | A job was dispatched |
| `job.started` | debug | A worker started a job |
| `job.completed` | debug | A job finished; `duration_ms` |
| `job.retry_scheduled` | warning | An attempt failed and will be retried; exception class and scrubbed message |
| `job.failed` | error | A job gave up (retries exhausted); the permanent failure |
| `job.timed_out` | error | A worker killed a job that ran too long |
| `email.queued` | info | An email was accepted into the app's queue |
| `email.sending` | info | A worker is calling the provider; `attempt` |
| `email.sent` | info | The provider **accepted** the message (not delivered); `duration_ms` |
| `email.failed` | warning | Not accepted: `reason` is a code (`rejected`, `not_configured`, `outcome_unknown`, …) |
| `email.retry_scheduled` | warning | A temporary provider failure; the job will retry |
| `email.delivered` / `email.bounced` / `email.complained` | info | A provider delivery event was applied |
| `email.reconciled` | warning | A provider event proved a send recorded as failed was in fact accepted |
| `webhook.verified` / `webhook.rejected` / `webhook.duplicate` / `webhook.received` | info / warning | Inbound and delivery-event webhooks: authentication outcome and intake |
| `webhook.processed` / `webhook.failed` / `webhook.ignored` / `webhook.replayed` | info / error / info | Processing outcome, with `attempt`, `duration_ms`, `correlation_id` |
| `automation.event.rejected` / `automation.event.ignored` / `automation.limit_reached` | warning / info | An event was not evaluated, and why |
| `automation.run.created` / `automation.run.duplicate` / `automation.run.finished` | info | A run was started, was already handled, or finished without actions |
| `automation.action.executed` / `automation.action.rejected` / `automation.action.limit_reached` | info / warning | A step's outcome |
| `automation.action.retry_scheduled` / `automation.action.failed` | warning / error | A step failed temporarily, or for good |
| `automation.chain_limit_reached` / `automation.steps_recovered` | warning | Safety limits and recovery sweeps |
| `billing.webhook.rejected` / `billing.webhook.duplicate` / `billing.webhook.failed` / `billing.webhook.misconfigured` | warning / info / warning / error | Stripe webhook intake and processing |
| `billing.sync.failed` / `billing.sync.rejected` | warning | Subscription synchronisation problems |
| `billing.checkout.started` / `billing.checkout.rejected` / `billing.subscription.started` | info / warning | Checkout and subscription lifecycle |
| `health.database_unavailable` | warning | The readiness check could not reach the database |

Example (fictional values):

```json
{"message":"Job attempt failed; it will be retried.","context":{"event":"job.retry_scheduled","job":"App\\Jobs\\SendEmailJob","attempt":2,"max_attempts":5,"duration_ms":412.3,"retryable":true,"correlation_id":"req-7f3a9c10","exception":{"exception":"App\\Exceptions\\Email\\EmailProviderException","message":"The email provider did not respond.","code":null,"location":"PostmarkEmailProvider.php:121"}}}
```

## 3. Correlation IDs

- `App\Http\Middleware\AssignCorrelationId` runs first on every request. It takes the caller's `X-Request-Id`
  when it is 8–64 characters of letters, digits, `.`, `_`, `-`; otherwise it generates a UUID. Unsafe values
  (newlines, spaces, oversized) are replaced, never echoed.
- The ID is in Laravel's `Context`, so every log line of the request carries it, and it is returned in the
  `X-Request-Id` response header.
- Queued jobs dispatched by a request carry the ID automatically (Laravel's Context travels with jobs), so the job's
  logs and any email it sends (`email_messages.correlation_id`) trace back to the request.
- Artisan commands and scheduled tasks have no request: they get a fresh ID per run from the same Context.
- The ID is a label. It is never used for authentication or authorization, and it contains no personal data.

## 4. Redaction

`App\Support\Logging\SensitiveDataRedactor` runs on every record, in every channel. It removes:

- **Keys by name** (any depth): anything containing `password`, `secret`, `token`, `authorization`, `cookie`,
  `signature`, `api_key`, `session`, card and bank fields, connection strings, server and account tokens.
- **Content keys:** `body`, `body_text`, `body_html`, `html`, `text`, `content`, `attachments`, `payload`,
  `raw`, `headers`, `request`.
- **Values by pattern:** bearer and basic credentials; user information in URLs; query strings; Stripe-style
  keys; key=value pairs in free text; IPv4 addresses; opaque runs of 40+ characters (reply tokens, hashes).
- **Email addresses** are masked to the first letter and the domain (`j***@acme.com`).
- **Exceptions** are logged as class, scrubbed message, code and origin (`file:line`). Never the trace, the
  arguments, or the raw message. Unhandled exceptions are logged once, with the route name (never the URL) and the
  user ID.
- **Objects** are written as `[object Class]`, never dumped.

Tests: `tests/Feature/Observability/RedactionTest.php` (11 tests) cover each rule and the channel tap.

Limit: redaction is pattern-based. A secret in a format none of the patterns recognise can still get through.
Keep secrets out of log context at the source, and add a pattern when a new format appears.

## 5. Error responses

- Expected outcomes keep their status codes and are not reported: validation (422), authentication (401/redirect),
  authorization (403), missing resources (404), method and CSRF (405, 419), throttling (429).
- Unexpected exceptions (500) return to the user only a generic message and a `reference`, which is the
  correlation ID. JSON clients get `{"message": "...", "reference": "..."}`; web visitors get
  `resources/views/errors/500.blade.php` with the reference.
- With `APP_DEBUG=true` (development) Laravel's normal debug output is unchanged.
- Livewire validation and redirects are untouched.

## 6. Queue lifecycle

`App\Listeners\LogQueueLifecycle` (registered in `AppServiceProvider`) logs every job's life from Laravel's own
queue events. A job's failure is logged once, by that listener. The exception handler skips exceptions the listener
has logged, so the same failure never appears twice.

Retry semantics, idempotency and failed-job storage are unchanged. Permanent failures still land in Laravel's
`failed_jobs` table and show in `php artisan queue:failed` and `php artisan reliability:report`.

## 7. Investigating a failure

**A failed queued job.** Search the logs for `event:job.failed` (or `job.retry_scheduled` for attempts that
will retry). The record gives the job class, attempt, duration and exception class. Take the `correlation_id` and
search for it to see the request or command that dispatched the job. Then `php artisan reliability:report` for
counts by class, and `php artisan queue:retry <id>` once the cause is fixed.

**An email.** `php artisan email:trace <message-id>` shows the message's status, timestamps, attempts,
correlation ID and provider events. In logs, `email.queued` → `email.sending` → `email.sent` (accepted) → a
delivery event. `email.failed` gives the reason code. "Accepted" is not "delivered": wait for `email.delivered`.

**An inbound reply or webhook.** `webhook.received` gives the webhook event ID. `webhook.failed` gives the
attempt count and exception class. `php artisan webhooks:failed` lists failed events with their reason; after fixing the
cause, `php artisan webhooks:replay <id>` processes one again. Replay refuses events whose payload was redacted by
retention.

**A billing webhook or sync.** Search for `billing.webhook.*` and `billing.sync.*`. A duplicate is expected
(Stripe retries); a `billing.webhook.failed` means Stripe will retry, and the event is recorded so it is not lost.
`billing.webhook.misconfigured` means `STRIPE_WEBHOOK_SECRET` is missing. Never paste a payload or a signature into a
ticket.

**An automation.** `automation.action.failed` names the action run ID; the run's logs are in the automation's log
screen. `automation.event.rejected` means the event pointed at another organization's records, which should not
happen and is worth investigating.

## 8. Health endpoints

| Endpoint | Answers | Status codes | Notes |
|---|---|---|---|
| `GET /up` | The application boots | 200 | Laravel's built-in liveness route. Does not touch the database. |
| `GET /health/ready` | The database answers a query within 2 seconds | 200 `{"status":"ok"}` / 503 `{"status":"unavailable"}` | Public, rate limited (60/min), `Cache-Control: no-store`. The body never names a host, a port or an error. |

Limits:
- Queue health is not checked. A reachable database does not mean jobs are being processed. Watch
  `reliability:report` and the `job.*` events instead.
- The scheduler is not checked. Watch for `follow-ups:process-due` and recovery commands running (see
  `docs/queue-reliability.md`).
- Email provider and Stripe reachability are not checked on every probe, because that would cost provider calls.
- There is no authenticated diagnostic endpoint. None is needed for the current deployment; add one only behind
  authorization if an operator needs one.

## 9. Recommended alerts

Each alert names the evidence needed. Thresholds are starting points, to be tuned on real traffic.

| Alert | Evidence | Severity |
|---|---|---|
| Readiness failing | `/health/ready` returns 503 on 2 consecutive checks | critical |
| Permanent job failures rising | `job.failed` count above 5 in 10 minutes | error |
| Email provider failing | `email.failed` with reason `unavailable` or `not_configured` above 10% of `email.sending` over 15 min | critical |
| Unknown email outcomes | any `email.failed` with reason `outcome_unknown` | warning (manual check) |
| Webhook processing failing | any `webhook.failed` or `billing.webhook.failed` | error |
| Webhook intake rejected in bulk | `webhook.rejected` or `billing.webhook.rejected` above 20 in 10 minutes | warning (possible misconfiguration or abuse) |
| Stripe misconfigured | `billing.webhook.misconfigured` | critical |
| Stripe sync failing | `billing.sync.failed` above 3 in 1 hour | error |
| Unhandled errors rising | `exception.unhandled` above 10 in 10 minutes | error |
| Automation steps failing | `automation.action.failed` above 5 in 1 hour | warning |
| Scheduler stalled | no `automations:recover-stalled` or `follow-ups:process-due` output for 15 minutes (cron/heartbeat, not in the app) | critical |

Each alert needs a log-search or metrics backend, and a notification channel. Neither is configured here (section 11).

## 10. Log retention and production settings

Recommended production configuration (set in the deployment environment, not committed):

- `APP_ENV=production`, `APP_DEBUG=false`. The application forces debug off in production and logs a critical
  event if it is found on (Task 16A).
- `LOG_LEVEL=info`. Keeps `job.started` and `job.completed` out of production logs.
- `LOG_CHANNEL=daily` with `LOG_DAILY_DAYS=14`, or ship `stack` to your platform's log collector. The default
  `single` file grows without rotation.
- Retention: keep operational logs 14–30 days; `email.*` and `webhook.*` may reference customer domains and
  reply IDs, so restrict access to them the way you restrict the database.
- The redaction tap protects the content of logs, not who can read them. Limit log access to operators.

Platform constraints: this repository does not configure a log shipper, a process manager or a metrics exporter,
because the deployment platform is not known here. Where the platform captures stdout/stderr, use the `stderr`
channel (`LOG_STACK=stderr`) and let the platform rotate.

## 11. Manual configuration still required

- Choose the log destination and retention (section 10).
- Wire a log search and an alert notification channel for section 9. Nothing is sent to Slack, email, PagerDuty
  or similar from this code.
- Point the load balancer or deploy check at `/health/ready` (and optionally `/up`).
- Configure the Postmark events and inbound credentials and check them with `php artisan webhooks:check`.

## 12. Known limitations and deferred work

- Redaction is pattern-based (section 4).
- The `/health/ready` probe does not cover queues, scheduler, or providers (section 8).
- Metrics are not exported. Counts are available from `reliability:report` and by searching `event` in logs.
- Not every older log call has an `event` key yet. The automation, Stripe, billing-sync and webhook calls do; a few
  other service logs (for example team, onboarding and settings) do not. Adding keys to them is mechanical.
- Web error pages are rendered for production 500s only. Other status pages are Laravel's defaults.
- The queue listener logs debug-level start and completion for every job. At high volume, set `LOG_LEVEL=info`.

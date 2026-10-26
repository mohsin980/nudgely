# Audit logs and administrative activity (SA-10)

`/admin/audit-logs` is the read-only audit trail of platform administration. It reuses the existing `platform_audit_logs` table (one audit system; the customer-facing `organization_activity` feed is separate and never carries internal reasons).

## What an event contains

| Field | Meaning |
| --- | --- |
| `action` | Structured name, `<target>_<past-tense verb>`: `organization_suspended`, `user_deactivated`, `plan_updated`, `subscription_refreshed`, `platform_admin_granted`, `admin_session_started`, ... |
| `outcome` | `success`, `denied` (a permission check refused it) or `failed` (it was allowed but could not complete, e.g. the provider was unreachable) |
| `actor_id` | The administrator; empty for the system or a server command (`platform-admin:grant`, retention) |
| `target_type` / `target_id` | What it was about: organization, user, plan, subscription, ... |
| `organization_id` | Related organization, where relevant. Not a foreign key: the event outlives the organization |
| `reason` | The reason the administrator gave (internal) |
| `changes` | Sanitized details: old and new values, counts, identifiers |
| `correlation_id` | The request ID (`X-Request-Id`), to match the event to application logs |
| `created_at` | When |

IP addresses, user agents and email addresses are not stored (data minimization); the request ID links to infrastructure logs if those are needed.

## What is recorded

Organization suspension / reactivation, user deactivation / reactivation, plan edits and resets, subscription refreshes from the provider, platform access granted / revoked (server commands, actor = system, details name the roles), admin panel session start (once per session), expiry removals (`audit_log_pruned`), and **denied** attempts at the sensitive actions above (who tried what on which target; the values they tried to set are not recorded) and **failed** provider refreshes.

Not recorded here: ordinary customer activity (see `organization_activity`), page views, and failed sign-ins (rate limited by the panel; see application logs).

## Redaction

`PlatformAuditLog::record()` is the only way in and always runs `AuditRedactor`:

- Keys naming secrets or message content (`password`, `token`, `api_key`, `secret`, `authorization`, `cookie`, `card`, `cvc`, `iban`, `body`, `html`, `content`, `payload`, ...) become `[redacted]` regardless of value.
- Values that look like secrets are replaced in any field including free text: Stripe keys and webhook secrets, bearer tokens, JWTs, long opaque tokens (40+ characters), Luhn-valid card numbers. Email addresses become `[email]`.
- Depth, width and string length are capped; objects are never serialized.

Customer email bodies, credentials and payment details are never inputs to the audit log in the first place; redaction is the safety net.

## Tamper resistance

Events are append-only: the model refuses updates and deletes, the policy denies create / edit / delete to every role, and the panel has no such actions. Only the retention command removes events (below), and it records itself. For a hardened deployment also give the application's database user no `UPDATE`/`DELETE` privilege on `platform_audit_logs` except through a separate maintenance account used by `platform-audit:prune`.

## Access

Permission `view_audit_logs` (support admin and super admin; not billing admin). Customers and guests cannot reach it.

## Search, filters, scale

Newest first by ID with simple pagination (no `COUNT(*)` over the table). Search matches actions (by name or label), exact target ID, organization ID, actor ID or request ID. Filters: action, outcome, actor, organization ID, date range. All use indexed columns (`action`, `actor_id`, `target_type/target_id`, `outcome`, `organization_id/created_at`, `created_at`).

## Retention

Events are kept `ADMIN_AUDIT_RETENTION_DAYS` (default 730, two years; minimum 30). `php artisan platform-audit:prune` (scheduled daily at 03:45, `--dry-run` to preview) removes older events in batches of 1,000 and writes one `audit_log_pruned` event with the count. Adjust the period to your legal and contractual requirements; export before shortening it.

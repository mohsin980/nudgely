# Security audit & multi-tenant hardening (Task 16A)

Scope: the implemented code (models, policies, gates, middleware, controllers, Livewire, services, jobs, webhooks).
Every finding below was verified against the code, and every fix has a regression test in `tests/Feature/Security`.

## Result
The application was already strongly isolated: organization comes only from `Auth::user()->organization`, policies compare
`organization_id`, Livewire ids are `#[Locked]`, tokens are hashed, webhooks verify signatures. No CRITICAL or HIGH
cross-tenant defect was found; probes (URLs, Livewire mounts/crafted calls, services) all failed to cross tenants.
The work was defence in depth.

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| F1 | MEDIUM | Cross-tenant links were prevented only in application code; a future bug could store a record pointing at another organization | PostgreSQL triggers `enforce_same_organization()` on 30 link columns (migration `2026_10_24_000000`) |
| F2 | MEDIUM | No browser security headers | `SecurityHeaders` middleware: nosniff, DENY framing, referrer policy, permissions policy, COOP, HSTS (prod/HTTPS), CSP (report-only default, `SECURITY_CSP=enforce`) |
| F3 | MEDIUM | Password change left other sessions/remember tokens alive | `AccountSecurity::endOtherSessions()` on password change |
| F4 | MEDIUM | Invitation link (secret) was held in public Livewire state, visible in the page snapshot | Kept in server session; computed property |
| F5 | LOW | `is_default` (EmailConnection) and `status` (Automation) were mass assignable | Removed from `$fillable`; set through services |
| F6 | LOW | Automation tester resolved `assign_to` names across all organizations | Scoped by organization |
| F7 | LOW | No rate limits on billing actions / portal route | `billing` limiter (10/min) and per-organization action limit (30 / 10 min) |
| F8 | LOW | Debug could be enabled in production; session cookie not forced secure | Production forces `app.debug=false` (logged critical); `session.secure` defaults on in production |

INFO (verified, no change): public estimate token encrypted + hashed lookup with no-store/noindex/no-referrer; reply routes hashed with
`hash_equals`; Postmark basic auth with `hash_equals`, size limit, event-id uniqueness; Stripe signature + tolerance + event idempotency;
logo uploads (mimes + mimetypes + `getimagesize`, random name, nosniff/CSP on serve); inbound HTML sanitized (no img/script/js links);
last-owner protected by a partial unique index and service checks; suspended/removed users signed out by `EnsureActiveMember`;
jobs re-check state (cancelled, replied, paused/deleted automation, verification) at execution time.

## Remaining risks / limitations
- CSP ships report-only: Livewire/Alpine need inline scripts and eval; verify in a real browser before `SECURITY_CSP=enforce`.
- Triggers cover link columns known today; new link columns need a trigger (add to the migration list pattern).
- No 2FA, no login-anomaly alerting, no dependency-audit CI step (`composer audit`).
- Rate limits are per-process cache based; use Redis in production for accuracy across servers.

## Recommended Task 16B
Enforce CSP after browser verification, add 2FA, add `composer audit`/`npm audit` to CI, add audit-log retention/export, and
review production logging for PII.

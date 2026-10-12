# Super Admin panel: production readiness, deployment and rollback (SA-12)

Status of this document: written from a repository and local-environment review. **No deployment was performed**; nothing here claims the panel runs in production.

## What is included (SA-01 to SA-11)

Filament admin panel at `/admin` with platform RBAC (Spatie), dashboard, organizations (with suspension), users (deactivation), plans (limits), subscriptions (provider data, refresh), usage and quotas, audit log, and the authorization / tenant-isolation test suites. Per-module notes: `docs/admin-*.md`, `docs/rbac.md`, `docs/security-review.md`.

## Requirements

- PHP 8.3, Composer 2, MySQL 8 (the application targets MySQL; PostgreSQL support was not re-verified for these migrations), the existing queue worker and scheduler.
- **No frontend build is required for `/admin`**: Filament ships its own assets (published into `public/js/filament`, `public/css/filament`, which are git-ignored). They are published by `php artisan filament:upgrade`, which runs from Composer's `post-autoload-dump`, so `composer install` must be run **with scripts enabled** (not `--no-scripts`). The customer app's existing `npm run build` (Vite) is unchanged and still required for the customer UI.
- No new queue workers: the admin features add no queued jobs.
- One new scheduled command, `platform-audit:prune` (daily 03:45). The existing `php artisan schedule:run` cron entry runs it.

## Environment variables (all optional, shown with defaults)

| Variable | Default | Purpose |
| --- | --- | --- |
| `ADMIN_PANEL_BRAND` | `QuoteFlow AI` | Name shown in the panel |
| `ADMIN_AUDIT_LOG_CHANNEL` | (default channel) | Log channel for refused panel entry |
| `ADMIN_AUDIT_RETENTION_DAYS` | `730` | Audit retention; minimum 30 |
| `BILLING_STALE_AFTER_MINUTES` | `180` | When provider-confirmed subscription data is called stale |
| `BILLING_USAGE_WARNING_PERCENT` | `80` | "Nearing limit" threshold on the usage page |

Secrets are only ever read from the environment (`STRIPE_*`, `APP_KEY`, DB credentials); a test scans the source for hardcoded keys and administrator passwords. Production must set `APP_ENV=production` and `APP_DEBUG=false`. `.env` is not tracked.

## Deployment steps

1. Merge via reviewed pull requests, in order (the SA branches are stacked: SA-01 through SA-12). Do not push to `main`.
2. Back up the database.
3. `composer install --no-dev --optimize-autoloader` (scripts enabled; publishes Filament assets).
4. `php artisan migrate --force` (8 additive migrations, listed below; about 10 seconds on an empty database; the index migration on `messages`/`estimates`/`follow_ups` locks time in proportion to table size, so run it in a quiet period on large tables).
5. `php artisan db:seed --class=PlatformRolesAndPermissionsSeeder --force` (safe to repeat: verified 4 roles / 17 permissions / 32 grants after repeated runs).
6. `php artisan config:cache route:cache view:cache event:cache` (or `php artisan optimize`) and restart queue workers as usual.
7. Provision the first super administrator on the server only: see `docs/admin-security.md` (`php artisan platform-admin:grant`). There is no public way to create an administrator.
8. Smoke test: sign in at `/admin/login`, open each module; confirm a customer account gets 403 on `/admin`, and `/customers` redirects guests to `/login`.

Migrations added (all additive; none drops or rewrites existing customer data):

`2026_10_29 create_platform_admins_table`, `2026_10_30 create_permission_tables`, `2026_10_31 add_suspension_and_platform_audit` (organizations.suspended_at/by, platform_audit_logs), `2026_11_01 add_subject_to_platform_audit_logs`, `2026_11_02 create_plan_settings`, `2026_11_03 add_last_synced_at_to_subscriptions`, `2026_11_04 index_usage_counts`, `2026_11_05 extend_platform_audit_logs`.

## Behaviour changes to existing code (review these)

- `bootstrap/app.php`: with `APP_DEBUG=false` the generic-500 renderer swallowed routine outcomes, so a guest opening a protected page or a failed sign-in returned HTTP 500 instead of a redirect / validation errors. Found during this review on the real server; fixed so only unexpected failures get the generic 500 page. Covered by tests. This affects customer-facing behaviour in production and needs reviewer attention.
- Customers in a suspended organization are signed out on their next request and the `access-organization` gate refuses them.
- `EntitlementService`, `UsageService` and `PlanCatalog` gained read-only variants (batched usage, plan overrides from `plan_settings`); `BillingService` now records `last_synced_at` and exposes `invoicesFor()`.
- Filament's unused export/import download routes are closed (404).

## Rollback

- **Preferred:** redeploy the previous release and leave the new tables/columns in place. They are additive and unused by the old code. Caveats: organizations suspended through the panel become usable again (the old code does not read `suspended_at`); plan overrides in `plan_settings` stop applying (plans revert to `config/billing.php`); users deactivated through the panel stay suspended (that is the existing member status).
- **Schema rollback** (only if necessary): `php artisan migrate:rollback --step=8 --force`. This was run and verified on a scratch MySQL database (rollback and re-migrate both succeed). It **destroys data**: `platform_audit_logs` (the audit trail), `plan_settings`, `platform_admins`, role tables, `suspended_at/by`, `last_synced_at`. Export the audit log first. A defect in the `extend_platform_audit_logs` rollback (MySQL refused to drop an index needed by a foreign key) was found and fixed in this branch; migrations deployed before that fix should be rolled back with the fixed file.
- Config rollback: removing the new environment variables returns defaults.

## Known limits (not blockers for review, relevant before production use)

See `docs/security-review.md`. The two to weigh first: suspension does not pause background work (F3), and the admin panel shares the customer session without multi-factor authentication (F4).

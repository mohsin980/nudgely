# Authorization and tenant-isolation review (SA-11)

Scope: the Super Admin panel (SA-01 to SA-10) and the customer-facing application's tenant boundaries. Evidence is automated tests only (`tests/Feature/Security`, `tests/Feature/Admin`); this is **not** a penetration test and does not show the whole application is secure. Not covered: infrastructure and deployment configuration, dependency vulnerabilities, browser-side attacks (XSS/CSRF beyond the existing header and CSP tests), load/DoS, third-party services (Stripe, email providers), and the mail/webhook endpoints beyond signature handling.

## Permission matrix (as tested)

| Capability | Super | Support | Billing | Owner | Org user | Guest |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| Open `/admin` dashboard | yes | yes | yes | no (403) | no (403) | login redirect |
| Organizations list and details | yes | yes | yes | no | no | no |
| Users list and details | yes | yes | no | no | no | no |
| Plans page | yes | yes | yes | no | no | no |
| Subscriptions list and details | yes | yes | yes | no | no | no |
| Usage list and details | yes | yes | yes | no | no | no |
| Audit logs | yes | yes | no | no | no | no |
| Invoices / webhook activity (`view_payments`) | yes | no | yes | no | no | no |
| Suspend / reactivate organization | yes | no | no | no | no | no |
| Deactivate / reactivate user | yes | no | no | no | no | no |
| Edit plan limits / reset | yes | no | no | no | no | no |
| Refresh subscription from provider | yes | no | no | no | no | no |
| Manage roles (`manage_roles`, Role policy) | yes | no | no | no | no | no |
| Edit/delete organizations, users, audit events | nobody | nobody | nobody | nobody | nobody | nobody |
| Bulk actions, exports | none exist | | | | | |

`PlatformAuthorizationTest` checks every cell: all 17 platform permissions against `PlatformRole::permissions()` for every actor, every admin route by direct HTTP request, and every state-changing service/action. A guard test fails if an admin route, a resource/page, a bulk action or an export appears without a matrix entry.

## Scenarios

| Required scenario | Test |
| --- | --- |
| Ordinary user cannot access `/admin` | `each admin page is open to exactly the roles…` (owner/staff 403 on all 12 admin URLs) |
| Owner cannot inspect another organization's records | existing `TenantIsolationTest` (URLs, Livewire mounts, forged properties, services) plus new search/filter/related-page tests |
| Support cannot do billing-only operations | `support cannot perform billing-only operations…` |
| Billing cannot manage roles | same test (create/update/delete Role, `manage_roles`) |
| No self-assigned privileges | `nobody can assign themselves… platform privileges`, `…manage_users still cannot touch more privileged accounts or themselves` |
| Unauthorized suspend / plan change blocked | `an unauthorized request cannot suspend… or modify a plan, through any door` and the forced hidden-action test |
| Cross-tenant IDs expose nothing | existing suite + `organization IDs and record IDs in the query string are ignored` |
| Unauthorized exports/bulk blocked | `no admin table offers bulk actions, and no export exists` (Filament's own export routes are closed, see finding F1) |
| Revoked permissions take effect next request | `revoking a role, the admin record or the account…`, `permission revoked while a page is open…`, `a customer who is demoted…` |
| Isolation in search, filters, details, related pages | `PlatformTenantIsolationTest` (customer search/filters/related pages; admin users table, usage, audit filter, invoices) |

Navigation visibility is verified against the server: each role's rendered menu equals the pages it may open, and every page it may not open is 403 on direct request.

## Findings

| # | Severity | Status | Finding |
| - | - | - | - |
| F1 | Low | **Fixed in this branch** | Filament registers `GET /filament/exports/{id}/download` and `/filament/imports/{id}/failed-rows/download` with only the `web` middleware group. The app has neither feature (no tables), so they returned HTTP 500 to guests and customers. Repro: `curl -i /filament/exports/1/download` while signed out. Now closed with 404 (`DisableUnusedFilamentRoutes`); tested for guest, customer and admin. |
| F2 | Low | **Fixed in this branch** | Admin pages kept display state in public Livewire properties (`invoices`, usage `row`/`activity`/`organizationId`). The browser could alter what was rendered (no stored data and no permission was affected). Now `#[Locked]`; tested. |
| F3 | Medium | Open (known since SA-05) | Suspending an organization signs its members out but does not pause background work: scheduled follow-ups, automations and outgoing email still run, and public estimate links and inbound webhooks still work. Code inspection: no service or job reads `suspended_at`. Repro: suspend an organization that has a due automated follow-up and run `follow-ups:process-due`. Fix: check the organization's suspension where jobs pick organizations and where email is queued. |
| F4 | Medium | Open (design trade-off) | The customer app and the admin panel share the `web` guard, session cookie and domain. An XSS or session theft in the customer app would also yield an admin session for a platform admin using the same browser. There is no multi-factor authentication for administrators. Mitigations present: CSP and security headers, panel entry is re-checked on every request, admin accounts should be dedicated. Fix: separate guard/subdomain for `/admin` and Filament app authentication (MFA). |
| F5 | Low | Open | Audit events are append-only in the application but not in the database: an operator with direct DB access (or a compromised app DB user) can alter them. Fix: revoke `UPDATE`/`DELETE` on `platform_audit_logs` from the application's DB user and run the prune as a separate maintenance user. |
| F6 | Low (pre-existing) | Open | No password-reset flow exists (no forgot-password pages), so locked-out users depend on an administrator or a database change. Not exploitable, but it pushes recovery toward manual, riskier handling. |
| F7 | Low (pre-existing) | Open | Email addresses are not verified at registration or sign-in. The platform-admin grant command therefore relies on the operator confirming the email is the right person's (documented in admin-security.md). |
| F8 | Info | Open | Failed admin sign-ins are rate limited but not written to the audit log (only to application logs); denied attempts at sensitive services are audited. |
| F9 | Info | Open | The usage page's plan/quota-status filters measure every organization in batches (linear in the number of organizations). A scalability note, not a security issue. |

No existing vulnerability was found in the customer-facing tenant boundaries by these tests; the pre-existing `TenantIsolationTest` and `AuthorizationTest` suites (Task 16A and 13) continue to pass unchanged.

## Test commands

```
vendor/bin/pest tests/Feature/Security      # authorization, isolation, hardening
vendor/bin/pest tests/Feature/Admin         # admin modules
vendor/bin/pest                             # everything
```

# Super Admin access security (SA-03)

## How access is decided

One rule, written in one place (`App\Support\Admin\AdminAccess`): a user may use the admin panel only if

1. the account is **active**,
2. it has a **`platform_admins`** row, and
3. its platform **role holds the permission** being asked for (always `access_admin_panel`, plus the page's own permission).

Guests, customers (owner / manager / staff) and suspended accounts are denied. Business roles never grant platform access.

| Layer | What enforces it |
| --- | --- |
| Every `/admin` request and every admin Livewire update | `AuthenticatePlatformAdmin` (persistent middleware): guests go to `/admin/login`, non-admins get 403, refusals to enter the panel are logged |
| Pages, resources, widgets | `RequiresPlatformPermission` trait: `canAccess()` / `canView()` use `AdminAccess`; menu, URL and Livewire calls all go through it |
| Actions and bulk actions | `->authorize(...)` / policies; unauthorized actions are hidden and cannot be called |
| Roles | `RolePolicy` (needs `manage_roles`) |

**Rule for new admin code:** every page, resource and widget must `use RequiresPlatformPermission` and name its permission. A test (`every page and widget in the admin panel declares a required permission`) fails the build if one is missing.

Access is re-checked on every request, so removing a `platform_admins` row, a role, or suspending the account stops that person on their next click.

## Sign-in protection

- Admin sign-in is rate limited (5 attempts per minute per IP) by Filament.
- Failures show the same generic message for admin, customer and unknown accounts, so privileged accounts cannot be discovered.
- A customer who signs in on the admin form is refused the same way.
- There is no admin registration. Registration ignores any role or admin fields sent by the client.
- Refused panel entry is logged (`Admin access denied`: user id, permission, path, IP; no email or request data). Set `ADMIN_AUDIT_LOG_CHANNEL` to send them to a dedicated channel.

## Provisioning the first super administrator

Super admins are created only on the server, never through the web.

1. Deploy and migrate: `php artisan migrate --force`
2. Seed roles and permissions (safe to repeat): `php artisan db:seed --class=PlatformRolesAndPermissionsSeeder --force`
3. The person registers a normal account at `/register` with a **unique, strong password** (use a password manager), on a mailbox only they control. Do not reuse a customer account.
4. On the server (SSH or deploy console), run:
   `php artisan platform-admin:grant their@email.com --role=super_admin`
   Confirm the prompt after checking the email is exactly right (a typo would grant a stranger access). Use `--force` only in automation you trust.
5. They sign in at `/admin/login`.
6. Verify, then record who granted it and when: `php artisan tinker` -> `User::where('email', '...')->first()->hasPlatformPermission(PlatformPermission::AccessAdminPanel)`.
7. Keep super admins to the minimum (two, so one lockout is recoverable). Grant others `support_admin` or `billing_admin`.

To remove access: `php artisan platform-admin:revoke their@email.com` (effective on their next request).

Why not an email allowlist or a first-user-wins rule: registration is open and emails are not verified, so anyone could register a listed email and take over. Only someone with server access can grant platform access.

## Known trade-offs and follow-ups

- Customer app and admin panel share the `web` guard and session. A super admin who is also a customer is signed into both. Use a dedicated admin account.
- Two-factor authentication for admins is recommended next (Filament app authentication; needs users-table columns).

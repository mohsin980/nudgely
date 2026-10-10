# Super Admin panel (SA-01)

The platform owner's panel lives in this repository at **`/admin`**. SA-01 installs and secures it. It has one page
(Platform overview); organizations, users, plans, subscriptions and the rest are added in later tasks.

## What was installed

| Package | Version | Why |
|---|---|---|
| `filament/filament` | `^4.15` (4.15.1) | Admin panel, tables, forms, widgets |

Filament brings 31 supporting packages (`filament/*`, `pragmarx/google2fa*`, `chillerlan/php-qrcode`, …). **No existing
package changed version**: Laravel stays 13.34.0 and Livewire stays 3.8.10.

**Why Filament 4 and not 5:** Filament 5 requires Livewire 4. This application is on Livewire 3, which the customer
pages depend on, and replacing it was out of scope. Filament 4.15 supports Laravel 13 and Livewire `^3.8.7`.

**PHP:** Filament needs the `intl` extension. It is enabled locally; enable it on the hosting plan too (Hostinger:
hPanel → PHP Configuration → Extensions → `intl`).

## Who can enter `/admin`

An **active** account with a row in **`platform_admins`** and a platform role that holds `access_admin_panel` (SA-02). Business
roles (owner, manager, staff) never grant it. Roles and permissions are described in [rbac.md](rbac.md).

The grant is created on the server, for an account that already exists, with an explicit role:

```bash
php artisan platform-admin:grant founder@example.com --role=super_admin   # never creates an account; refuses inactive accounts
php artisan platform-admin:revoke founder@example.com
```

**Why not an email allowlist in `.env`:** registration is open and email addresses are not verified, so anyone could
register with a listed address before its owner and be let in. A database row that only a command can create has no
web path to it. There is no registration page, form or API for administrators (`/admin/register` is a 404).

## Configuration

| File | What it does |
|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` | Panel id `admin`, path `/admin`, `web` guard (same `users` table and session as the customer app), Filament login, violet theme, navigation groups (Platform, Customers, Billing, Access, System) |
| `config/admin.php` | `brand` (`ADMIN_PANEL_BRAND`, default `QuoteFlow AI`): the panel title |
| `app/Models/User.php` | Implements `FilamentUser`; `canAccessPanel()` = admin panel + active account + platform admin + `access_admin_panel` |
| `app/Models/PlatformAdmin.php` | The grant. Mass assignment is blocked on purpose |
| `app/Filament/Pages/Dashboard.php` | "Platform overview" page |
| `app/Filament/AvatarProviders/InitialsAvatarProvider.php` | Initials avatar drawn locally. Filament's default sends the user's name to `ui-avatars.com` |
| `database/migrations/2026_10_29_000000_create_platform_admins_table.php` | The new table |
| `app/Console/Commands/PlatformAdmin{Grant,Revoke}Command.php` | The two commands above |
| `composer.json` | `filament:upgrade` added to `post-autoload-dump` so `composer install` publishes Filament's assets |
| `.gitignore` | Published Filament assets (`public/css|js|fonts/filament`) are generated, not committed |
| `bootstrap/providers.php` | Registers the panel provider |

The customer application is untouched: its routes, layouts and CSS are unchanged, and the panel uses Filament's own
assets (no second CSS framework in the customer pages).

## Notes for the next tasks

- Done in SA-02: the business-membership `Gate::before` now steps aside for active platform administrators and platform
  abilities, so policies on platform models work for an administrator who has no business ([rbac.md](rbac.md)).
- Models that read across organizations (SA-05 onward) must be queried deliberately, since the customer application
  scopes everything by `organization_id`.
- The platform administrator is also a normal user of their own business and keeps that access.

## Verification

```bash
php artisan route:list --path=admin          # /admin, /admin/login, /admin/logout (no register route)
php artisan test --filter=AdminPanelTest     # 21 tests: access, sign-in, commands, existing pages
php artisan test                             # whole suite
```

Manual check: run `php artisan platform-admin:grant you@example.com`, then open `/admin`, sign in, and you should see
"Platform overview" under the QuoteFlow AI title. A normal business account signing in at `/admin/login` is refused.

See [admin-organizations.md](admin-organizations.md) for organization management.

See [admin-dashboard.md](admin-dashboard.md) for the dashboard metrics and their definitions.

See [admin-security.md](admin-security.md) for how access is enforced and how to provision the first super administrator.

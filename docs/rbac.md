# Roles and permissions (SA-02)

QuoteFlow has **two separate permission systems**. They never mix.

| | Platform (SA-02) | Business (existing) |
|---|---|---|
| Who | The people who run the SaaS | A customer's own owner, manager and staff |
| Scope | The whole platform, through `/admin` | One business only |
| Defined in | `App\Enums\Platform\PlatformRole` / `PlatformPermission` | `App\Enums\OrganizationRole` / `Team\Permission` |
| Stored in | Spatie tables (`roles`, `permissions`, `model_has_roles`, …) and `platform_admins` | `users.role` |
| Checked with | `can('manage_plans')` (underscores) | `can('manage-team')` (hyphens) |

A platform role gives **no** access to business data, and a business role gives **no** platform permission. The customer
pages scope every query by `organization_id`; a platform administrator who is also a user of their own business sees only
that business there.

## Packages

| Package | Version |
|---|---|
| `spatie/laravel-permission` | `^8.3` (8.3.0). Supports Laravel 13 and PHP 8.3; added with no other package changing |

Guard: **`web`**, the guard the panel and the customer app share. Teams are off (platform roles are not per business).
Config: `config/permission.php`. Migration: `2026_10_30_000000_create_permission_tables.php`.

## Roles and what they may do

* **super_admin**: everything.
* **support_admin**: look up businesses, users, plans, subscriptions, usage and the audit log to help customers. Changes nothing and cannot see payments.
* **billing_admin**: see plans, subscriptions, payments and usage. Changes nothing unless a billing action is granted to that person separately. Cannot see users or the audit log.
* **business_owner**: a customer. Defined so the name exists, but it holds **no** platform permission. It is not assigned automatically and cannot be given with the grant command.

| Permission | super_admin | support_admin | billing_admin | business_owner |
|---|:-:|:-:|:-:|:-:|
| `access_admin_panel` | ✓ | ✓ | ✓ | – |
| `view_dashboard` | ✓ | ✓ | ✓ | – |
| `view_organizations` | ✓ | ✓ | ✓ | – |
| `manage_organizations` | ✓ | – | – | – |
| `suspend_organizations` | ✓ | – | – | – |
| `view_users` | ✓ | ✓ | – | – |
| `manage_users` | ✓ | – | – | – |
| `view_plans` | ✓ | ✓ | ✓ | – |
| `manage_plans` | ✓ | – | – | – |
| `view_subscriptions` | ✓ | ✓ | ✓ | – |
| `manage_subscriptions` | ✓ | – | – | – |
| `view_payments` | ✓ | – | ✓ | – |
| `manage_payments` | ✓ | – | – | – |
| `view_usage` | ✓ | ✓ | ✓ | – |
| `view_audit_logs` | ✓ | ✓ | – | – |
| `manage_roles` | ✓ | – | – | – |
| `manage_system_settings` | ✓ | – | – | – |

The matrix lives in code (`PlatformRole::permissions()`), and a test checks the database against it. To change what a role
can do, edit that method and re-run the seeder.

## How access is decided

A platform permission (for example `manage_plans`) is allowed only when **all three** are true
(`User::hasPlatformPermission()`):

1. the account is **active** (suspended and removed accounts are refused);
2. the user has a row in **`platform_admins`** (created only by the grant command);
3. one of the user's roles holds the permission.

So a role attached to a customer by mistake grants nothing, and a `platform_admins` row with no role grants nothing.

Spatie's own global gate is **turned off** (`register_permission_check_method` is `false`). Left on, it would answer
"yes" for any permission a user holds, skipping checks 1 and 2. Instead, each permission is registered as an explicit gate in
`AppServiceProvider`. Two tests fail if that setting is turned back on.

Where it is enforced (not only in menus):

* `User::canAccessPanel()`: the panel needs `access_admin_panel`.
* `App\Filament\Pages\Dashboard::canAccess()`: the dashboard needs `view_dashboard`.
* Gates: `can('view_payments')`, `@can(...)`, `Gate::allows(...)`, route middleware `can:manage_plans`.
* `App\Policies\RolePolicy`: managing roles needs `manage_roles`, and the four built-in roles can never be deleted.
* The business gate `Gate::before` skips active platform administrators and platform abilities, because a platform
  administrator does not have to belong to a business. Everyone else who is not an active member is still denied everything.

## Assigning roles to administrators

There is **no web form, registration option or API** for this. It happens on the server, for an account that already exists:

```bash
# once per environment, and after any change to the role definitions (safe to repeat):
php artisan db:seed --class=PlatformRolesAndPermissionsSeeder --force

# make an existing user an administrator (a role is required; nobody is super_admin by default):
php artisan platform-admin:grant founder@example.com --role=super_admin    # asks to confirm; add --force in scripts
php artisan platform-admin:grant helper@example.com  --role=support_admin
php artisan platform-admin:grant finance@example.com --role=billing_admin

# change someone's role: the roles named replace the previous ones
php artisan platform-admin:grant helper@example.com --role=billing_admin

# remove access and roles (the account itself stays)
php artisan platform-admin:revoke helper@example.com
```

The seeder only creates roles and permissions. It never assigns a role to anyone. It sets each built-in role to exactly its
defined permissions, so re-running it also restores a role someone changed by hand.

## Checking permissions in code

```php
$user->can('view_payments');                                   // gate
$user->hasPlatformPermission(PlatformPermission::ViewPayments); // same, typed
Gate::authorize('manage_plans');                               // 403 when denied
```

Add a new permission by adding a case to `PlatformPermission`, deciding which roles get it in `PlatformRole::permissions()`,
and re-seeding. It becomes a gate automatically.

## Not in this task

The role-management screens (SA-06 onward). `RolePolicy` is ready for them. Business-level roles are unchanged.

## Verification

```bash
php artisan test --filter=RolesAndPermissionsTest   # seeder, matrix, allowed and denied checks, policy, commands
php artisan test --filter=AdminPanelTest            # panel access and sign-in
php artisan test                                    # whole suite
```

See [admin-security.md](admin-security.md) for enforcement details (middleware, page trait, audit log) and first super admin provisioning.

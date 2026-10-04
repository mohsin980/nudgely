# Business settings, team members & permissions (Task 13)

## Accounts

- **Sign up** (`/register`): creates a business and its **owner** in one transaction. Everyone else joins by invitation.
- **Sign in / out** (`/login`, `POST /logout`): plain Laravel session auth, rate limited. Suspended and removed members can't sign in (same message as a wrong password).
- `EnsureActiveMember` middleware (web group) signs a suspended/removed person out on their next request and records `last_active_at` (at most every 5 minutes).

## Roles and permissions

Three fixed roles (`App\Enums\OrganizationRole`). What each may do is defined once in `OrganizationRole::permissions()`; every `App\Enums\Team\Permission` case is also a Gate of the same name (`can:manage-team`, `@can('manage-email')`). `Gate::before` denies everything to people who aren't active members.

| | Owner | Manager | Staff |
| --- | :-: | :-: | :-: |
| Customers, conversations, estimates, follow-ups, tasks | ✓ | ✓ | ✓ |
| View automations and execution logs | ✓ | ✓ | ✓ |
| Create / edit / activate automations | ✓ | ✓ | – |
| Re-run AI classification | ✓ | ✓ | – |
| Estimate, follow-up, automation and notification defaults | ✓ | ✓ | – |
| Business profile, preferences, business hours | ✓ | – | – |
| Email sender, domain verification, automatic-email safety | ✓ | – | – |
| Team (invite, roles, suspend, remove) | ✓ | – | – |
| Transfer ownership | ✓ | – | – |
| Own notifications, password, sessions | ✓ | ✓ | ✓ |

Rules: exactly **one owner** per organization (also a partial unique index); the owner can't be suspended, removed or demoted. Ownership only moves with **Transfer ownership** (current password + confirmation; the previous owner becomes manager or staff). Nobody changes their own membership.

Organization ownership vs. assignment: the organization owns all data; `tasks.assigned_to`, `follow_ups.assigned_to`, `automations.created_by`, `estimates.created_by` only record responsibility. Work can only be assigned to **active members of the same organization** (`TeamDirectory`).

## Team (`/settings/team`, owner)

- **Invite** (name, email, manager/staff). `InvitationService` creates a 64-character random token; only its sha256 is stored. Links are single-use, expire after `config('team.invitation_days')` (7), and stop working when revoked or **resent** (a resend replaces the invitation). The email goes through `EmailService` from the verified sender; its body is removed from `messages` once sent (`redact_after_send`). Without a verified sender the owner gets the link to share.
- **Accept** (`/invitations/{token}`): shows business and role; creates the account (or brings back a removed member of the same business) with the invited role and signs them in. Expired: "This invitation has expired."
- **Statuses**: active, suspended, removed (users) and invited (open invitations). Nothing is deleted; history keeps names.
- **Suspend / reactivate**: suspended members are signed out everywhere (sessions + remember token) and denied every action.
- **Remove**: shows the member's open responsibilities (automations they created, open tasks and follow-ups). Automations must be handed to an active member; tasks/follow-ups move to that member or become unassigned.

Limitation: one account belongs to one business (`users.organization_id`); an email used by another business can't be invited.

## Settings pages

| Page | Who | What |
| --- | --- | --- |
| `/settings/business` | Owner | Name, legal name, email, phone, website, address (all optional but the name), logo (PNG/JPEG/WebP ≤ 2 MB, checked by content, random name, served by `LogoController`, also shown on customer estimates), current sender + Manage Email |
| `/settings/preferences` | Owner | Timezone (any IANA zone), currency (USD, CAD – no conversion; used for new estimates), date format, 12/24-hour time, default task priority, business hours |
| `/settings/email` | Owner | Existing email connection / domain verification page |
| `/settings/notifications` | Everyone | Own in-app/email choices per type; owners and managers also set business defaults |
| `/settings/team` | Owner | See above |
| `/settings/roles` | Everyone | Read-only matrix generated from `OrganizationRole::permissions()` |
| `/settings/estimates` | Owner, manager | Validity days, default notes, default tax rate → pre-filled on new estimates |
| `/settings/follow-ups` | Owner, manager | Default delay (days) and time → schedule form and new “Create follow-up” automation actions |
| `/settings/automation` | Owner, manager | Default automation owner (stands in for an inactive creator), default task assignee, default notification recipient |
| `/settings/security` | Everyone | Email, membership, change password, active sessions + log out other sessions; owner: danger zone (transfer ownership) |

Business defaults live in `organizations.settings` (jsonb) behind `OrganizationSettings` (typed getters with fallbacks). Every key there is used by the app. Values typed on a form always win over a default.

Business hours are stored for scheduling; `BusinessHours::nextOpening()` is the extension point — the automation engine does not move sends into business hours yet.

Dates: `Organization::formatDate() / formatTime() / formatDateTime() / formatCalendarDate()` apply the business timezone and formats (used on settings, team, estimates and follow-up times).

Organization deletion is not offered (no fake button): it needs retention/billing decisions first.

## Notifications

Types: new customer reply, estimate accepted, estimate declined, automation failure, follow-up due, task assigned; channels: in-app and email. A person's choice (`user_notification_preferences`, per organization) beats the business default (`settings.notifications`), which beats the built-in default (in-app on except customer replies; email off). `TeamNotifier` delivers (idempotent per event and person; email through `EmailService`, skipped without a verified sender); `ActivityNotifications` decides who hears about what. Only active members are notified, never the person who caused it.

## Audit

`organization_activity` (same shape as `automation_history`): who, to whom, what changed (`{field: {from, to}}`), when — profile, logo, preferences, hours, all defaults, invitations, role changes, suspensions, removals, ownership transfers, password changes and session sign-outs. Each settings page shows its change history.

## Tests

`tests/Feature/Team/`: `BusinessSettingsTest` (1–7, 41–43), `TeamManagementTest` (8–40, security, sign-up), `TeamEndToEndTest` (the four scenarios), `BusinessWorkflowEndToEndTest` (sign-up → staff completes an automation task).

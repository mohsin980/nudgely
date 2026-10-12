# User and access management (SA-06)

`/admin/users` lists customer user accounts and lets authorized administrators deactivate and reactivate them. Nothing here edits, deletes or impersonates an account, and nothing grants platform privileges.

## Who can do what

| Action | Permission | Support | Billing | Super |
| --- | --- | --- | --- | --- |
| List, search, filter, open details | `view_users` | yes | no | yes |
| Account history (internal reasons) | `view_audit_logs` | yes | no | yes |
| Deactivate / reactivate a customer account | `manage_users` | no | no | yes |
| Deactivate / reactivate a platform administrator | `manage_users` **and** `manage_roles` | no | no | yes |

Rules live in `UserPolicy` and are enforced again in `UserAccountService`, so a crafted request is refused even when the button is hidden. Nobody can act on their own account.

## List and details

Columns: name, email, organization, business role, status, email verified, created. Search matches name or email. Filters: organization (searchable), status, business role, email verified. Paginated (25 / 50 / 100); query count is constant (organization and platform-admin flag are eager loaded). Only display columns are selected, so password hashes and tokens are never loaded.

Details show account, organization membership (linked to the organization page for those who may view it), platform access (read-only) and the account history. Never shown: passwords or hashes, reset and remember tokens, sessions, provider credentials, message content.

"Email verified" reflects `email_verified_at`. The app does not require verification to sign in today (owners who register stay unverified; invited members are verified when they accept), so this is information, not an access rule. Verification and sign-in rules are unchanged.

## Deactivation

- Confirmation dialog and a mandatory reason (10 to 500 characters).
- Uses the existing account lifecycle: status becomes `suspended`, exactly as when a business owner suspends a member.
- **Sessions and tokens:** all database sessions are deleted and the remember-me token is rotated at once (`TeamService::endSessions`). The status is also read on every request, so an open page is refused on the next click. The app has no API tokens.
- Signing in is refused while deactivated (existing rule: only active accounts can sign in).
- Reactivation restores access with the existing password. For business members it takes a team seat again, so the plan's seat limit applies, as in the customer app.
- Refused: your own account; business owners (suspend the organization instead); accounts that are not active / not deactivated; platform administrators unless you hold `manage_roles`.
- **Audit:** every change writes a `platform_audit_logs` row (actor, subject user, organization, action, reason). The reason is internal. The business's own member history gets a plain "Member suspended / reactivated" entry with no actor and no reason.

## Privilege escalation

- There is no ability to create users, edit roles, or grant platform roles from the panel. Platform privileges are granted only on the server (`php artisan platform-admin:grant`, see admin-security.md).
- Support and billing staff cannot assign `super_admin` or change their own or anyone's privileges, and an administrator holding `manage_users` alone cannot touch platform administrators.
- A customer administrator can only manage their own organization (`TeamService` looks members up inside the actor's organization); platform actions require platform permissions that customers never hold.

## Not included

- **Password reset:** the app has no password-reset flow today (no forgot-password or reset pages; only Laravel's unused token table). Sending a reset link from the panel would need that customer-facing flow first, so it is not offered. When it exists, add a "Send password reset link" action that calls the password broker, never sets or shows a password.
- Impersonation and unrestricted account access.
- Multi-factor authentication for administrators (see admin-security.md).

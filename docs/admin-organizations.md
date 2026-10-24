# Organization management (SA-05)

`/admin/organizations` lists every customer business and lets authorized administrators inspect and suspend them. Nothing here creates, edits or deletes an organization.

## Who can do what

| Action | Permission | Support | Billing | Super |
| --- | --- | --- | --- | --- |
| List / search / filter, open details | `view_organizations` | yes | yes | yes |
| Subscription section of the details | `view_subscriptions` | yes | yes | yes |
| Usage section | `view_usage` | yes | yes | yes |
| Users table | `view_users` | yes | no | yes |
| Platform history (suspensions) | `view_audit_logs` | yes | no | yes |
| Suspend / reactivate | `suspend_organizations` | no | no | yes |

Rules live in `OrganizationPolicy` (create, update and delete always refuse) and are checked again in `OrganizationSuspension`, so a crafted request is refused even if the button is hidden. Customers (owner / manager / staff) and guests cannot open any of these pages; the organization ID in a URL is only ever read after the policy passes.

## List

Columns: ID, business, status, users, plan, subscription status, created. Search matches the name or the exact ID. Filters: status, subscription status, plan. Paginated (25 / 50 / 100). Query count does not grow with the number of rows (members are one `withCount`, subscriptions one eager load); a test enforces it.

"Plan" and "Subscription" come from the organization's current (not cancelled or expired) subscription; no subscription means the Free plan.

## Details (read-only)

Business facts, subscription, usage against plan limits, members, and platform history. Never shown: passwords, tokens, email-provider credentials, customer records or message content.

## Suspension

- **Required:** a confirmation dialog and a reason (10 to 500 characters).
- **Effect on the business:** every member is signed out on their next request and cannot use the app or sign in (they are sent to the sign-in page with "This business account is suspended"). Public estimate links and inbound webhooks are not changed.
- **Effect on data and billing:** none. Customers, users, subscription and billing records stay exactly as they were. Nothing cascades or is deleted.
- **Reversible:** Reactivate (also needs a reason) restores access immediately.
- **Audit:** every suspend and reactivate writes a `platform_audit_logs` row (actor, organization, action, reason, time) in the same transaction. The reason is internal: it is not stored on the organization and not written to `organization_activity`, which businesses can read.
- **Guard:** you cannot suspend the organization your own account belongs to.

## Not included (follow-ups)

- Background work for a suspended organization (scheduled follow-ups, automations, outgoing email) is not paused yet. Add a suspended check where those jobs pick organizations before relying on suspension to stop outbound messages.
- Billing is not touched: a suspended business with a paid subscription is still billed until it is cancelled through the (future) billing module.
- Permanent deletion needs a separate, approved retention workflow and is deliberately not offered.

## Search, filters and email status (added with the dashboard redesign)

- **Search** matches the business name, the email of its owner (the account with the owner role), or the exact organization ID. Staff email addresses are not searched.
- **Signup date** filter: businesses created between two dates, inclusive.
- **Email sending** section on the detail page: provider, sender domain, verification status and connection status (active, needs reconnecting, or disconnected). Sender addresses, refresh tokens and SMTP passwords are never shown. A business with no sending account shows "No sending account has been set up."

Not built: impersonation of a business user (nothing exists to restrict or audit), a global search bar, and a notifications menu.

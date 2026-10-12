# Plans and feature limits (SA-07)

`/admin/plans` lists every plan and lets authorized administrators edit its display name and limits. It does not create or delete plans, change prices, or touch Stripe.

## What is owned by whom

| Field | Source of truth | Editable here? |
| --- | --- | --- |
| Which plans exist (key) | `config/billing.php` | No |
| Price, billing interval | `config/billing.php` for display and MRR estimates; **what is charged is the Stripe price** | No |
| Provider price ID (Stripe) | `config/billing.php` / env (`STRIPE_PRICE_*`) | No |
| Features list (plan-page bullets) | `config/billing.php`; display text only, not enforced anywhere | No |
| Display name | config, overridable | **Yes** (display change) |
| Limits (customers, automations, team members, outbound emails, estimates) | config, overridable | **Yes** (entitlement change) |

Changing a price means creating a new Stripe price, putting its ID in the environment and updating the config in a deploy. That is a billing change and is deliberately not available in the panel. A plan marked "No provider price ID" is not purchasable while billing runs on Stripe.

Overrides live in `plan_settings` (one row per plan). `PlanCatalog` applies them on top of config; invalid or unknown rows are ignored, so a bad row can never break billing. "Reset to configured" deletes the row.

## Who can do what

| Action | Permission | Support | Billing | Super |
| --- | --- | --- | --- | --- |
| View plans | `view_plans` | yes | yes | yes |
| Edit limits / name, reset | `manage_plans` | no | no | yes |

The permission is checked again in `PlanSettingsService`, so a crafted request is refused when the button is hidden.

## Validation

- Limits: whole numbers 0 to 1,000,000, or empty for unlimited. Team members must be at least 1 (otherwise the owner is locked out).
- Name: 2 to 40 characters, no `<` or `>`, unique among plans.
- A dearer plan may not allow less than a cheaper one (and may not lose "unlimited"), or an upgrade would be a downgrade.
- A reason of 10 to 500 characters is required, and saving with no change is refused.

## What happens to existing subscribers

- **Limit changes apply immediately to everyone on that plan**, including businesses in the sign-up trial of that plan. Limits are read live by `EntitlementService`, so enforcement (customer, automation, seat, email and estimate creation) uses the new numbers on the next action.
- **Raising** a limit gives room at once.
- **Lowering never deletes or disables anything.** Businesses already above the new limit keep all their data and are only stopped from adding more until they are back under it. The edit form previews how many subscribers are already above a lowered limit (first 200 subscribers are inspected).
- **Name changes** are display only.
- **Billing is untouched.** No Stripe call is made and no subscription row, price or invoice is modified, so billing history stays accurate. The free plan applies to every business without a paid plan, so its limit changes affect all of them.
- **Plans with active subscribers cannot be deleted or invalidated:** plans cannot be deleted at all, and the key, price, interval and provider price ID that subscriptions refer to are never changed by this module.

## Audit

Every edit and reset writes a `platform_audit_logs` row: who, the reason, and the old and new value of each changed field (`changes`). Reasons are internal.

## Not included

Creating plans, editing prices or intervals, syncing with Stripe products, hiding/retiring plans from checkout, and feature toggles (features are not enforced in the backend today, so editing them would imply behaviour that does not exist).

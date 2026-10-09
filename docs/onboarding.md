# Onboarding & activation (Task 15)

A new business owner is taken from sign-up to a working QuoteFollow in a few short steps. Onboarding owns no business logic: every save goes through the existing services (business settings, customers, estimates, automations, email, billing).

## State (stored on the organization)

`business_type`, `onboarding_step`, `onboarding_progress` (json), `onboarding_started_at`, `onboarding_completed_at`, `onboarding_skipped_at`, `onboarding_checklist_done_at`. Businesses that existed before this feature are marked completed by the migration.

Steps (`OnboardingStep`): `business_profile` → `business_preferences` → `email_connection` → `first_customer` → `first_estimate` → `first_automation` → `completed`. Progress is **derived from what really exists** (a business type, a verified default email connection, a real customer, an estimate, an automation); `onboarding_progress` only remembers what has no record of its own (location confirmed) and what was skipped. Only the business profile is required. The current step is the first one neither done nor skipped, so leaving and returning resumes in the right place, and skipping is never counted as progress.

## Flow

- Registration starts onboarding (`OnboardingService::begin`) and redirects to `/onboarding` (owner only; gate `manage-onboarding`). Invited members are never onboarded and get 403 on the wizard.
- `RedirectToOnboarding` (alias `onboarding`) is on `/dashboard` only: an owner who has neither finished nor skipped onboarding is redirected. Billing, account security, email settings, sign-out and the wizard itself are never redirected, so there are no loops. "Skip for now" sets `onboarding_skipped_at` and creates nothing.
- The wizard (`App\Livewire\Onboarding\Wizard`): welcome → business → location/timezone (state suggests a timezone, editable) → email (links to the existing Email settings; continues by itself once a verified default connection exists) → customer → estimate (a draft with the business's defaults; nothing is sent) → automation → "You're ready" summary → dashboard. Errors keep the user on the step and never lose saved progress.
- Automations: suggestions come from `config/onboarding.php` (business type → template keys of `AutomationTemplates`). **Use This Automation** shows a review (trigger, wait, condition, action, sender, recipient, approval mode) and activates only after an explicit confirmation and **Activate Automation**; **Customize** installs a draft only. Whether emails need approval follows the business's existing automation settings.
- Dashboard: a "Getting started" checklist (owner only, disappears when everything is done) and an empty-state with Add Customer / Create Estimate / Create Automation.
- Billing: the wizard shows the trial days left from `EntitlementService`, includes the billing banner, and sends the owner to billing first if the subscription is `incomplete`/`unpaid`.

## Sample data safety

`customers.is_demo` marks a sample customer (one per business, email `sample-xxxx@example.invalid`). It is shown with a "Sample" badge, doesn't count as the first real customer or toward the plan's customer limit, never receives email (`EmailService` refuses any recipient that is a sample customer, before any usage is counted), and never triggers automations (`AutomationEngine` ignores its events). "Delete sample data" removes it and what was created for it.

## Development

`php artisan onboarding:reset {organization}` restarts onboarding (refuses in production without `--force`; deletes no business data). There is no reset in the UI.

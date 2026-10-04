# Automation rules & workflow builder (Task 12)

Business owners build their own rules: **WHEN** (trigger) → **WAIT** (optional) → **IF** (conditions) → **THEN** (actions).
The engine itself (runs, idempotency, retries, email safety) is described in [automation-foundation.md](automation-foundation.md).

## Pages (owners/admins only; members get 403, other organizations' records 404)

| URL | Livewire | Purpose |
| --- | --- | --- |
| `/automations` | `Automations\AutomationIndex` | Cards: name, status, trigger, actions, executions, last run, created. Failure banner, starter templates, email safety toggles, "show archived". |
| `/automations/create`, `/automations/{id}/edit` | `Automations\AutomationForm` | Step builder: 1 Name → 2 When → 3 Wait & If → 4 Then → 5 Review (summary, problems, test run, Save draft / Activate). |
| `/automations/{id}` | `Automations\ShowAutomation` | Summary, stats, history; activate, pause, archive, restore, duplicate, delete (only without runs). |
| `/automations/{id}/logs[/{runId}]` | `Automations\AutomationLogs` | Executions filtered by Success / Failed / Skipped / Pending; detail shows condition results and each action result. |

The old `/settings/automations/*` addresses redirect (301).

## Status (`AutomationStatus`)

`draft` (default, never runs) · `active` · `paused` (new events ignored; waiting runs are skipped when they resume) · `archived` (never runs, read-only, restore → draft).
Nothing becomes active without an explicit **Activate**, which re-validates everything (`AutomationBuilder::activationErrors()`): trigger, conditions, at least one action, template variables for that trigger, and — for customer email — automatic email enabled plus a verified sender.

## Registries (single source of truth for UI, validation and execution)

`App\Services\Automation\Registry`:

- **`TriggerRegistry`** – label, description and the records each trigger provides (`Subject`: customer, conversation, message, classification, estimate, follow_up). Only triggers the engine dispatches are listed: customer created, customer reply received (with AI intent), estimate created/sent/viewed/accepted/declined/expired, follow-up due/completed, conversation closed/reopened.
- **`ConditionFieldRegistry`** – typed fields (string, number, money, percent, enum, boolean, date) with their allowed operators; a field is offered only when the trigger provides its records. Values are parsed strictly; dates accept `YYYY-MM-DD`, `today`, `today+N`/`today-N` and are compared in the organization's time zone.
- **`ActionRegistry`** – fields, validation rules and trigger exclusions for: send email (customer or owner), create follow-up (email or reminder), complete/cancel follow-up, create task (assign to owner or a user), notify, add/remove tag, change conversation status.
- **`VariableRegistry`** – `{{customer.first_name}}`, `{{customer.last_name}}`, `{{customer.name}}`, `{{customer.email}}`, `{{business.name}}`, `{{conversation.subject}}`, `{{estimate.number}}`, `{{estimate.title}}`, `{{estimate.total}}`, `{{estimate.valid_until}}`, `{{follow_up.due_at}}`. Unknown variables (e.g. `{{php_code}}`) and variables the trigger can't provide are rejected on save/activation. Templates are plain substitution — no code is evaluated.

Conditions are combined with **ALL** or **ANY** (one level, up to 10 conditions).

## Execution

1. Trigger event → `EvaluateAutomationJob` (queued after commit).
2. With a WAIT, a `waiting` run is stored with `resume_at` (no sleeping workers). `automations:resume-waiting` (scheduler, every minute) leases due runs and queues `ResumeAutomationRunJob`; the job claims the run atomically, re-checks status and conditions **with current data**, then runs the actions or records the skip reason (e.g. *Customer already replied.*, *The automation was paused while it was waiting.*).
3. Without a WAIT, conditions are evaluated immediately; non-matching events create no run.
4. Each action runs in its own job with retries; results are stored per action run.

Run status: `pending`, `running`, `waiting` (shown as Pending in filters), `completed` (shown as **Success**), `failed`, `skipped`. `condition_results` stores each condition's label, actual value and pass/fail.

## Idempotency

- One run per (automation, event type, event id) — unique index.
- One action run per (run, action) — unique index; a job claims it atomically (`pending → running`, stale `running` reclaimed).
- Side effects carry a key `sha256(org | action | trigger | event id)`: email (`messages.automation_key` unique), tasks and follow-ups (`idempotency_key`), notifications (UUIDv5). A retried job finds the existing record and logs *already exists* instead of duplicating.

## Testing a rule

**Test run** on the Review step runs the rule against a real record (customer, reply, estimate, follow-up or conversation) inside a transaction that is rolled back: conditions are explained and actions previewed (rendered email, task title, due dates). Nothing is sent, created or logged.

## Starter templates (installed as drafts)

Follow up after estimate · Ready to book alert · Estimate accepted · Interested customer follow-up (plus price objection, callback, scheduled estimate follow-up, estimate declined).

## Audit, dashboard, timeline

- `automation_history`: created, edited, activated, paused, archived, restored, duplicated (who and when).
- Dashboard: automation activity (including *Waiting until …*) and a notice when executions failed in the last 7 days.
- Customer timeline: automation runs for the customer (waiting/skipped/failed titled accordingly).

## Not implemented (by design)

External integrations/webhooks, code or JavaScript execution, AI-generated workflows or emails, SMS/WhatsApp/calls, nested condition trees, drag-and-drop canvas, advanced analytics.

## Tests

`tests/Feature/Automations/AutomationRulesTest.php` (spec items 1–38 + duplicate/archive and dry run) and `AutomationWorkflowEndToEndTest.php` (the three end-to-end scenarios).

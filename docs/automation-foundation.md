# Automation foundation (7A.1)

This is the data model and events that QuoteFollow automations will be built on.

**Nothing executes yet:** there is no condition evaluation, action execution, engine, builder UI or automated email. Those come in later 7A tasks.

```
Automation (organization, name, status, trigger_type, created_by/updated_by)
 ├── AutomationCondition[]   type + operator + value, AND-combined, ordered
 ├── AutomationAction[]      type + configuration (JSON) + requires_approval, ordered
 └── AutomationRun[]         one per automation per triggering event
       └── AutomationActionRun[]   one per action per run, with result / error
```

## Enums (`App\Enums\Automation`)

Values are stored in the database, so never rename them. `AutomationEnumsTest` pins them.

| Enum | Values |
| --- | --- |
| `AutomationStatus` | `draft` (default), `active`, `paused` |
| `AutomationTriggerType` | `customer_reply_received`, `customer_reply_classified`, `estimate_sent`, `estimate_viewed`, `estimate_expired`, `follow_up_due`. `isAvailable()` is true only for the two reply triggers, the ones the app dispatches today. |
| `AutomationConditionType` | `intent_equals`, `confidence_greater_than`, `customer_status_equals`, `estimate_status_equals`, `days_since_last_message`, `conversation_status_equals`. `allowedOperators()` restricts each type to the operators that make sense for it. |
| `AutomationConditionOperator` | `equals`, `not_equals`, `greater_than`, `greater_than_or_equal`, `less_than`, `less_than_or_equal` |
| `AutomationActionType` | `create_task`, `schedule_follow_up`, `send_email`, `add_customer_tag`, `update_conversation_status`, `notify_user` |
| `AutomationRunStatus` | `running`, `completed`, `failed`, `skipped` |
| `AutomationActionRunStatus` | `pending`, `running`, `completed`, `failed`, `skipped` |

- **Conditions are data, never code.** A condition stores a type, an operator and a value string, which the engine will compare. Nothing is ever evaluated as PHP.
- **No dangerous actions.** Price changes, discounts, deletions, bookings, refunds, payment and permission changes don't exist as action types.
- **`send_email` requires approval by default.** `AutomationAction` sets `requires_approval = true` for it unless the value is set explicitly.

## Database guarantees

- **Idempotency:** `automation_runs` has a unique `(organization_id, automation_id, event_type, event_id)`, so the same event can't run the same automation twice. Events with no ID (`null`) are not deduplicated by this constraint.
- **One run per action:** `automation_action_runs` has a unique `(automation_run_id, automation_action_id)`, so each action executes at most once per run.
- **Tenant pinning:** a composite foreign key `automation_runs (automation_id, organization_id) → automations (id, organization_id)` means the database refuses a run whose organization differs from its automation's.
- **Cascades:** deleting an automation removes its conditions, actions and runs, and deleting an organization removes everything. `created_by` and `updated_by` become null when a user is deleted. An action run keeps its history if its action is later deleted (`automation_action_id` becomes null).
- **Indexes:**
  - active automations by organization, status and trigger
  - runs by organization and time, by automation and time, and by status

## Organization isolation

- `organization_id`, `created_by` and `updated_by` are not mass assignable; they must come from the authenticated context.
- Use `Automation::forOrganization($organization)` or `$organization->automations()` for every query in a tenant context, and `->activeFor($trigger)` for the engine lookup.
- `AutomationPolicy` allows view, create, update and delete only for **admins of the automation's organization**. Members and other organizations are denied.

## Events (`App\Events`)

Every event implements `App\Contracts\Automation\AutomationEvent`:
- `organizationId()`
- `triggerType()`
- `eventId()`: a stable occurrence ID used in the idempotency key.

Every event also implements `ShouldDispatchAfterCommit`: it is delivered only after the surrounding transaction commits, and dropped if the transaction rolls back. Events carry IDs and small facts only, never message content or secrets.

| Event | Payload | Dispatched |
| --- | --- | --- |
| `CustomerReplyReceived` | organization, message, conversation, customer | Yes: by `InboundEmailProcessor`, for replies attached to their conversation (not "needs review") |
| `CustomerReplyClassified` | organization, message, conversation, customer, classification, intent, confidence | Yes: by `CustomerReplyClassificationService`, after a **new** successful classification is saved (automatic or reclassify). Not on failures or duplicate jobs. |
| `EstimateSent` / `EstimateViewed` / `EstimateExpired` | organization, estimate, optional customer and conversation | No: there is no estimates feature yet |
| `FollowUpDue` | organization, follow-up, optional conversation and customer | No: arrives with scheduled follow-ups |

There are no listeners yet. The events only announce what happened.

# Conditions and actions framework (7A.2)

There is still no engine, queue execution, UI or automated email. These are the reusable building blocks the engine will call.

## Context

`App\Services\Automation\AutomationContext` holds what an automation knows about an event:
- organization, trigger, event ID
- customer, conversation, message and classification IDs
- intent and confidence

`AutomationContext::fromEvent($event)` builds it from any `AutomationEvent`. `customer()` and `conversation()` always load **within the context's organization**, so another tenant's ID resolves to `null`.

`render()` fills `{customer_name}`, `{customer_first_name}`, `{intent}` and `{conversation_subject}` by plain text substitution; nothing is evaluated.

## Conditions: `ConditionEvaluator`

`matches($automation, $context)` returns true only when **all** conditions pass (AND). An automation with no conditions matches every event.

| Type | Operators | Value | Compared with |
| --- | --- | --- | --- |
| `intent_equals` | equals, not_equals | a `CustomerReplyIntent` value | the event's intent |
| `confidence_greater_than` | >, ≥, <, ≤ | `0`–`1` (up to 4 decimals) | the event's confidence |
| `conversation_status_equals` | equals, not_equals | `open`, `waiting_customer`, `waiting_business`, `closed` | the conversation's status |
| `days_since_last_message` | >, ≥, <, ≤ | whole days, 0–9999 | days since the conversation's `last_message_at` |
| `customer_status_equals`, `estimate_status_equals` | — | — | **Rejected:** customers have no status and estimates don't exist yet |

Rules:
- **Missing data never matches.** For example, a confidence condition on a "reply received" event is false.
- **Invalid conditions throw `InvalidAutomationConditionException`** with a safe message: unknown type or operator, an operator not allowed for the type, or a value that doesn't parse strictly. `assertValid()` runs the same checks without evaluating, for the builder.
- **Values are parsed with strict patterns and compared with plain PHP operators.** The evaluator contains no `eval` or dynamic calls, and a test checks this.

## Actions: `AutomationActionManager`

`AutomationActionManager::execute($action, $context)` returns an `AutomationActionResult`: `success`, `status` (`completed`, `skipped` or `failed`), a `message` safe to show in logs, and small `data`. It never throws:
- An unknown stored action type fails as "Unsupported action."
- An action whose automation belongs to a different organization than the event fails before any handler runs.
- An unexpected exception becomes "The action failed unexpectedly." and is logged with the exception class only.

| Type | Handler | Configuration | Idempotency |
| --- | --- | --- | --- |
| `create_task` | `CreateTaskAction` | `title` (required, placeholders), `description`, `priority` low/medium/high, `due_in_hours` 0–8760, `assign_to` (user in the same organization) | `tasks.idempotency_key` = hash(organization, action, trigger, event) is unique, so a retry returns `skipped / already_exists` |
| `add_customer_tag` | `AddCustomerTagAction` | `tag` (normalized to a slug, max 50) | Unique `(organization_id, slug)` tag plus the pivot primary key; an existing tag returns `skipped / already_exists` |
| `update_conversation_status` | `UpdateConversationStatusAction` | `status`: open, waiting_customer, waiting_business or closed | Setting the current status returns `skipped / unchanged` |
| `notify_user` | `NotifyUserAction` | `message` (required, placeholders), `recipients` "admins" (default), "members" or a user ID in the organization, `channel` "in_app" only | Notification ID = UUIDv5(action + event + user), so a retry returns `skipped / already_exists` |
| `schedule_follow_up` | `ScheduleFollowUpAction` | — | Registered; returns `skipped / not_implemented` |
| `send_email` | `SendEmailAction` | — | Registered; returns `skipped / not_implemented`. It sends nothing; when built it must go through `EmailService` with opt-out, settings, approval and rate-limit checks. |

Every handler loads customers, conversations and users within the context's organization and fails safely on IDs from another tenant.

## New data

- `tasks`: organization, customer, conversation, assignee, title, description, priority, status (`pending`/`completed`/`cancelled`), `due_at`, `completed_at`, `idempotency_key`
- `customer_tags` (unique per organization by slug) and the `customer_customer_tag` pivot
- `notifications`: Laravel's standard database-notifications table
- `ConversationStatus` gains `waiting_customer` and `waiting_business`

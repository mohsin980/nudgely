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

In 7A.1 there were no listeners. Since 7B.1, `QueueAutomationEvaluation` queues an evaluation job for every automation event (see below).

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
| `send_email` | `SendEmailAction` | `subject`, `body` | See 7B.1: off by default, gated by `AutomatedEmailPolicy`, sends only through `EmailService`. |

Every handler loads customers, conversations and users within the context's organization and fails safely on IDs from another tenant.

## New data

- `tasks`: organization, customer, conversation, assignee, title, description, priority, status (`pending`/`completed`/`cancelled`), `due_at`, `completed_at`, `idempotency_key`
- `customer_tags` (unique per organization by slug) and the `customer_customer_tag` pivot
- `notifications`: Laravel's standard database-notifications table
- `ConversationStatus` gains `waiting_customer` and `waiting_business`

# Execution engine, queues and safety (7B.1)

```
AutomationEvent (after commit)
  ↓ QueueAutomationEvaluation listener
EvaluateAutomationJob (queue)
  ↓ AutomationEngine::evaluate()
active automations for the trigger → conditions → AutomationRun (unique per event)
  ↓ one AutomationActionRun per action (pending)
ExecuteAutomationActionJob (queue, one action at a time, in order)
  ↓ AutomationEngine::executeActionRun()
re-check → AutomationActionManager → record result → queue next action → close run
```

Nothing runs on the request or webhook path.

## Idempotency and concurrency

- **Same event, twice:** the run insert hits the `automation_runs_idempotency_unique` index and is skipped. The insert runs in a savepoint, so concurrent workers are race-safe: one wins, and the other logs "already handled" and creates nothing.
- **Same action job, twice:** the action run is claimed with an atomic `pending → running` update, so only one worker executes it. A run left `running` by a crashed worker can be reclaimed after `retries.stale_after_seconds`.
- **Handlers:** each keeps its own idempotency (task key, tag pivot, notification UUID, the email `automation_key`).

## Retries

| Failure | Behaviour |
| --- | --- |
| Temporary: `TransientAutomationException`, database deadlock or lost connection, transient provider error | The action run goes back to `pending` and the job retries (`retries.tries`, default 3, with backoff 10 s then 60 s). On the last attempt it is recorded as `failed` with "The action failed temporarily." |
| Permanent: bad configuration, other tenant, sender not verified, etc. | Recorded as `failed` at once with its reason; no retry |
| Job gives up (timeout, worker crash) | `failed()` records "The action could not be completed after retrying." |

One failed action doesn't stop the rest. A run ends `completed`, or `failed` with "N action(s) failed."

## Loop protection and limits (`config/automation.php`)

| Setting | Default | When exceeded |
| --- | --- | --- |
| `limits.max_chain_depth` | 10 | An event raised by an automation action is evaluated one level deeper (`AutomationExecutionScope`). At depth 10 a matching automation gets a `skipped` run: "Automation chain limit reached (depth 10)." It is logged and no actions run. |
| `limits.max_automations_per_event` | 25 | Extra automations aren't evaluated; a warning is logged |
| `limits.max_actions_per_run` | 10 | Extra actions are recorded as `skipped`: "Action limit reached for this run." |
| `limits.max_automated_emails_per_hour` | 20 per organization | `send_email` is `skipped / rate_limited` |
| `enabled` | true | A global kill switch |

## Organization isolation

The engine stops (logged, no runs) when:
- the organization doesn't exist;
- automations are disabled;
- the event's customer or conversation isn't in the event's organization;
- the customer doesn't own the conversation.

Before **each** action, it re-checks:
- that the run, the automation and the stored context share one organization;
- that automations are still enabled and the automation is still active;
- that the action still exists.

The action manager checks the organization again.

## Organization settings

| Column | Default |
| --- | --- |
| `automations_enabled` | `true` |
| `automatic_email_enabled` | `false` |
| `require_approval_for_email` | `true` |

`customers.email_opted_out_at` marks a customer who opted out. `EmailService::sendToConversation()` refuses opted-out customers for every caller.

## Email safety (`AutomatedEmailPolicy`)

`send_email` sends only if every check passes, in this order:
1. The organization exists.
2. Automations and automatic emails are on → else `skipped / automatic_email_disabled`.
3. Neither the organization nor the action requires approval → else `skipped / approval_required`. The action's `requires_approval` defaults to true.
4. The automation is active.
5. The conversation and its customer are in the organization.
6. The customer's email address is valid.
7. The customer hasn't opted out → else `skipped / opted_out`.
8. A default connection exists, its domain is verified and the sender is on that domain → else `failed`.
9. The email wasn't already sent for this event → else `skipped / already_exists`. A partial unique index on `messages.metadata->>'automation_key'` backs this.
10. The hourly rate limit isn't reached → else `skipped / rate_limited`.

The email is then queued with `EmailService::sendToConversation()`, never through a provider directly.

## Logging

Logs carry IDs, statuses, trigger, depth and reasons only:
- run created or duplicate;
- action executed, retried or failed;
- chain or limit reached;
- isolation rejections.

They never include message bodies, email addresses or secrets.

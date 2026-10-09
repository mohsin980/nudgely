# Business dashboard (Task 9)

`/dashboard` answers one question: **what needs my attention today?** It is not an analytics page.

## Sections

| Section | Shows | Source |
| --- | --- | --- |
| Header | Greeting, the date in the organization's timezone | `Organization::localNow()` |
| Summary cards | Overdue follow-ups, Due today, Waiting for you, New customer replies (last 24 h) | One aggregate query per table |
| Needs your attention | Up to 10 items, most urgent first (an overdue task before a task not yet due): conversations waiting for the business, overdue follow-ups, today's follow-ups whose time has come, and open tasks (below) | `DashboardService::attentionItems()` |
| Tasks | Open tasks that are overdue, due today or undated, with Complete; tasks due later are only counted | `tasks`, `ORDER BY due_at LIMIT 8` |
| Today's follow-ups | Overdue (separately), then today's, plus View All Follow-Ups | `follow_ups`, `ORDER BY due_at LIMIT 8` |
| Recent customer replies | Latest 6 replies with intent and confidence; each opens the conversation | `messages`, `LIMIT 6` |
| Quick actions | Add Customer (inline form), Schedule Follow-Up (`/follow-ups?schedule=1`), View Conversations, View Automations (admins) | Existing components |
| Notifications | The user's unread in-app notifications (Task 7/8 database notifications); Dismiss / Mark all read | `notifications` |
| Today | New customers, customer replies, follow-ups completed, follow-ups due, emails sent | Aggregate queries |
| Conversations | Open / Waiting on customer / Waiting on business / Closed | One grouped query |
| Automation activity | The last 5 runs with each action's result | `automation_runs`, `LIMIT 5` |

## Estimates (Task 11)

The Estimates card shows **Sent today** (delivered today), **Awaiting customer** (sent or viewed) and **Accepted today**, from one aggregate query. It is not a financial report: no revenue or totals are summed.

## Definitions

- **Overdue:** an open (pending or due) follow-up whose due time is before the start of today in the organization's timezone. This matches the Follow-Ups page, so the card and `/follow-ups?filter=overdue` always agree. A follow-up due earlier today counts under "Due today".
- **Due today:** an open follow-up due between the start and end of today in the organization's timezone.
- **Waiting for you:** `Conversation::scopeWaitingForBusiness()`. The same rule drives the inbox filter `?filter=waiting`. A conversation counts when it is not closed and not waiting on the customer, and at least one of these holds:
  - its status is `waiting_business` (set by an automation or a person);
  - the AI flagged it for review;
  - the latest intent is ready_to_book, wants_callback, complaint, question, needs_more_information or price_objection.
- **Tasks to do:** a pending task due by the end of today (overdue included) or with no due date. A task for a conversation that is already in the attention list is not listed twice there; it still appears under Tasks.
- **New customer replies:** inbound messages attached to a conversation in the last `DASHBOARD_NEW_REPLIES_HOURS` (24).

### Conversation status now follows whose turn it is

| Event | Status |
| --- | --- |
| Customer reply | `open` |
| An email from the business (manual, automated or follow-up) | `waiting_customer` |
| An automation explicitly sets it | `waiting_business` |

## Priority (`AttentionPriorityRules`)

| Priority | When |
| --- | --- |
| High | ready_to_book, wants_callback, complaint; AI urgency "high"; overdue follow-up; overdue task; high-priority task |
| Medium | interested, question, needs_more_information, price_objection; a follow-up due now; medium-priority task |
| Low | everything else, including low-priority tasks |

The AI is not the only authority:
- A high priority that comes from the AI needs at least 70% confidence (`dashboard.high_priority_min_confidence`); below that it is capped at medium.
- A conversation marked `waiting_business`, or flagged for human review, is always at least medium.

## Performance

**Bounded queries:** `DashboardService::snapshot()` makes a fixed number of queries (about 27), whatever the data size.

| Query type | How it's bounded |
| --- | --- |
| Counts | `COUNT(*) FILTER (WHERE …)` |
| Lists | `ORDER BY … LIMIT` |
| Relations | Eager-loaded, including `latestOfMany` for each conversation's latest reply and classification |
| Message bodies | Only a 300-character excerpt is read |

Tests assert that the query count doesn't grow with data and that every query is limited, aggregated or an eager load.

**New indexes:**
- `messages (organization_id, direction, received_at)`
- `messages (organization_id, direction, sent_at)`
- `conversations (organization_id, status, last_message_at)`
- `customers (organization_id, created_at)`
- `follow_ups (organization_id, completed_at)`

**Measured with 2 organizations**, each with 1,000 customers, 10,000 messages, 10,000 follow-ups, 120 automations and 2,000 runs:
- snapshot about 30 ms, 23 queries;
- slowest query 3 ms;
- index scans throughout.

## Refresh and loading

**Loading:** the page renders a skeleton first (`#[Lazy]`), then loads the data in one request. An "Updating…" status shows during refreshes.

**Refresh:**
- It re-renders every 60 seconds, only while the tab is visible (`wire:poll.60s.visible`).
- Actions (dismiss notification, add customer, complete task) update in place.
- There are no WebSockets.

## Security

- **Route:** requires sign-in and the `access-organization` gate (the user must belong to an organization). This is checked on the server for the initial request and again in the component.
- **Data:** every query uses the signed-in user's organization; nothing comes from the URL or the form.
- **Notifications:** they belong to the user, and only the user's own can be dismissed.
- **Tasks:** Complete looks the task up in the user's organization (404 otherwise), and `TaskService::complete()` checks the organization again.

## Accessibility

- Status and priority are always written in text (e.g. "▲ High priority", "Follow-up overdue", "Overdue (1)"), never color alone.
- Sections have headings and landmarks, links and buttons are real elements with visible focus rings, and the loading state uses `aria-busy`.

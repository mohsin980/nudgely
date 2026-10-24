# Business dashboard (Task 9)

`/dashboard` answers one question: **what needs my attention today?** It is not an analytics page.

## Layout

| Section | Shows | Source |
| --- | --- | --- |
| Top bar | Customer search (opens the Customers list filtered by the text) and a bell with the unread count that jumps to Notifications | `customers.index?search=`, `notifications` |
| Greeting | Greeting, the business name, the date in the organization's timezone, a one-line workload summary, and **Create Estimate** | `Organization::localNow()` |
| Quick actions | Add a customer (inline form), Create a quote, Reply to a message, Set a reminder | Existing routes and components |
| Key numbers | Six cards, each a link to the list it counts (see below) | `DashboardService` |
| Action required | Grouped problems with a count and, only where the user may use the destination, a button; below them, up to 10 individual customers to contact first | `DashboardService::alerts()`, `attentionItems()` |
| Upcoming & overdue follow-ups | Tabs for Today, Upcoming (next 7 days) and Overdue; opens on Overdue when nothing is due today | `follow_ups`, `ORDER BY due_at LIMIT 8` |
| Recent conversations | Latest 6 customer replies, tabs All / Need reply, intent badge when classified; each opens the conversation | `messages`, `LIMIT 6` |
| Tasks | Open tasks that are overdue, due today or undated, with Complete | `tasks`, `LIMIT 8` |
| Automation activity | Health, counts, the last 5 runs with each action's result | `automation_runs`, `LIMIT 5` |
| Your business at a glance | Plan name, plan usage bar, and used / limit for customers, estimates, team members and automations | `EntitlementService` |
| Setup checklist | What is done and what is left, from real configuration | `OnboardingService::checklist()` |
| Recent estimates | The five newest estimates, cancelled revisions left out | `estimates`, `LIMIT 5` |
| Notifications | Unread in-app notifications; Dismiss and Mark all read persist | `notifications` |
| Estimate pipeline | Counts per status, outstanding value, accepted value | `DashboardService::estimatePipeline()` |
| Today's activity | New customers, replies, follow-ups completed and due, emails sent, estimates sent | Aggregate queries |
| Conversations | Open / Waiting on customer / Waiting on business / Closed | One grouped query |
| Automation status bar | Running, needs attention, no recent runs, email not ready, or not set up | `DashboardService::automationHealth()` |

## Key numbers

| Card | Counts | Opens |
| --- | --- | --- |
| Estimates sent | Estimates whose `sent_at` is in the last 7 days, with the change against the 7 days before. The change is shown only when the earlier period has a number to compare with. | Estimates |
| Awaiting response | Estimates that are sent or viewed (never drafts, accepted, declined, expired or cancelled), and their total value | Estimates, `status=awaiting` |
| Follow-ups due | Open follow-ups due today in the business timezone | Follow-ups, `filter=today` |
| Overdue follow-ups | Open follow-ups due before the start of today | Follow-ups, `filter=overdue` |
| New replies | Customer replies received in the last 24 hours | Conversations |
| Accepted value | The totals of estimates accepted in the last 30 days, and how many | Estimates, `status=accepted` |

**Estimate values are not revenue.** "Outstanding" and "Accepted" are the totals written on the estimates. The app does not record what a customer later paid, so there is no "recovered revenue" figure and none is shown. Cancelled estimates (superseded revisions) are excluded everywhere, and every query is limited to the signed-in user's organization.

## Action required

Rows appear only when their count is above zero, customer replies and overdue follow-ups first:

| Row | Counts | Button (only if the user may use it) |
| --- | --- | --- |
| Customer replied | Conversations waiting for the business (same rule as the inbox "Waiting" filter) | View messages |
| Overdue follow-ups | As above | View follow-ups |
| Emails failed to send | Outbound messages with status `failed` created in the last 7 days. A retried send is one message. | View messages |
| Email sending needs attention | No verified, active default sending email | Fix now (people who manage email) |
| Automation needs attention | Automation runs that failed in the last 7 days | View automations (people who may view them) |
| Estimates expiring soon | Sent or viewed estimates valid until a date within 3 days, in the business calendar | View estimates |

If there are no rows and no customers waiting, the card says the business is all caught up. Failed emails are not retried from the dashboard: sending follows the existing retry and idempotency rules.

## Automation health

The status comes from results and readiness, never from an automation being enabled:

1. Email sending not ready (no verified, active default sender): **email not ready**
2. Any run failed in the last 7 days: **needs attention**
3. Active automations but no run completed in 7 days: **no recent runs**
4. Active automations with completed runs: **running**
5. Otherwise: **not set up**

## Demo data (development only)

`database/seeders/HvacDemoSeeder.php` fills a business with an HVAC scenario: eight customers, conversations with classified replies, follow-ups (overdue, today, upcoming), estimates in every status, automation runs including one failure, and one failed email. It refuses to run in production, needs an explicit owner, marks every customer as a demo customer (the app never emails them) and does nothing if run twice.

```
php artisan migrate:fresh            # development only: this wipes the database
php artisan db:seed                  # your normal seeders
DEMO_OWNER_EMAIL=owner@example.test php artisan db:seed --class=HvacDemoSeeder
```

The owner must already exist (register one in the app) and have finished onboarding to see the dashboard.

## Tests

```
php artisan test tests/Feature/Dashboard
php artisan test tests/Feature/Estimates/EstimateWorkspaceTest.php tests/Feature/Onboarding
```

They cover each card's rule, tenant isolation, the status filters, the follow-up and conversation links, notification state, empty states, business time zones, and a query budget that must not grow with the amount of data. No test sends email or calls an outside service.

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

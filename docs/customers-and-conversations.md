# Customer & conversation workspace (Task 10)

A lightweight communication workspace, not a CRM pipeline.

## Pages

| Route | What |
| --- | --- |
| `/customers` | Search, filters, sort, pagination (25/50/100) |
| `/customers/create` | Add a customer (the dashboard's Add Customer uses the same form) |
| `/customers/{id}/edit` | Edit a customer |
| `/customers/{id}` | Header (email, phone, company, status, notes), Send Email, Schedule Follow-Up, Edit; conversations; activity timeline; follow-ups; tasks |
| `/conversations` | Quick filters, status, priority, AI intent, follow-up and search filters; unread counts; 25 per page |
| `/conversations/{id}` | Chronological timeline, sticky reply composer, AI classification with correction, follow-ups, tasks, status/close/reopen |

Old `/inbox` URLs (including ones stored in notifications) redirect with a 301. The route names stay `inbox.index` and `inbox.show`.

### Customer list

**Search** covers first, last and full name, email, company and phone (digits only, so formatting doesn't matter). It runs in SQL against one `customers.search_text` column with a trigram index, and `%`/`_` in the search term are matched literally.

**Filters:**

| Filter | Options |
| --- | --- |
| Status | active, inactive |
| Conversation | open, waiting_customer, waiting_business, closed |
| Follow-up | overdue, due_today, upcoming, none |
| AI intent | interested, ready_to_book, price_objection, wants_callback, question, not_interested, other |

All are `EXISTS` subqueries; "today" uses the organization's timezone.

**Sorting:** recently active (the default), newest, oldest, name A–Z, name Z–A.

### Conversation list

**Filters:**

| Filter | Options |
| --- | --- |
| Status | open, waiting_customer, waiting_business, closed |
| Priority | high, medium, low |
| AI intent | ready_to_book, interested, price_objection, wants_callback, question, not_interested, other |
| Follow-up | overdue, due, scheduled, none |

Priority is computed in SQL (`AttentionPriorityRules::conversationPrioritySql()`). A test checks it matches the PHP rules for all 576 combinations.

**Each row shows:**
- unread count;
- last-message excerpt (300 characters at most, never full bodies);
- next follow-up.

These are loaded with `latestOfMany`, `withCount` and `withMin`. There's no N+1, and a test checks the query count doesn't grow with data. Priority is shown only while the business owes a reply.

## Rules

- **Customers:**
  - First name, last name and email are required; phone, company and notes are optional.
  - `name` stays in sync with first and last name, so emails, automations and AI context keep working.
  - Emails are trimmed and lowercased, and `(organization_id, email)` is unique in the database. A duplicate shows "Customer already exists." with [View Customer] and is never merged.
  - Status is active or inactive; customers are never deleted.
- **Status:**
  - An email from the business sets `waiting_customer`.
  - A customer reply sets `open`. This is Task 9's existing rule, kept unchanged; the spec's `waiting_business` is applied only by automations or a person.
  - A reply to a **closed** conversation reopens it, the reply is kept, and the timeline records "reopened by a customer reply".
- **Close:** takes an optional reason (completed, not interested, duplicate, no response, other). It keeps every message, skips the conversation's open **automated** follow-ups (`conversation_closed`), leaves manual reminders alone, and the conversation leaves "Needs your attention". **Reopen** sets it back to open.
- **Email:**
  - Sent through `ConversationService`, which calls `EmailService::sendToConversation()`. It goes from the organization's verified sender with a secure Reply-To and is queued.
  - An unverified sender or an opted-out customer gives "Unable to send email…" with a safe reason; nothing is queued and the attempt is logged.
  - A delivery failure is shown on the message as "Unable to send email: {safe reason}". Provider errors and credentials are never shown.
  - A customer with no open conversation gets a new one ("Send Email" on the customer page).
- **Reply matching:** unchanged from Task 5. Replies are routed by the secure Reply-To token, and senders that don't match go to "Needs review"; customer identity is never guessed.
- **Classification correction:**
  - It adds a `message_classifications` row with `source = manual`, `previous_intent`, `overridden_by`, `override_reason` and `classified_at`.
  - The AI's row is kept, and both appear in the history.
  - The conversation's intent is updated and a `classification_changed` event is recorded.
  - Automations are **not** re-run.
- **Unread:** `messages.read_at`. Opening a conversation marks only that conversation's customer replies read. Messages received before this feature count as read.
- **Timeline** (`TimelineService`): built from existing records, newest first with LIMITs:
  - messages, AI classifications and corrections;
  - automation runs with their action results;
  - follow-ups scheduled, completed, cancelled and skipped;
  - tasks created and completed;
  - conversations closed, reopened and status changes;
  - the customer's creation.

  On the conversation page the timeline is shown oldest first, labelled Customer, Business, System or Automation, and the newest 50 messages load first ("Load earlier messages").
- **Tasks:** people can create and complete tasks from the conversation or customer page. Automation-created tasks are labelled "by automation".

## Access

- **Routes:** sign-in plus the `access-organization` gate.
- **Policies:**
  - `CustomerPolicy` (view, create, update) and `ConversationPolicy::update` (reply, status, close/reopen, correct, tasks): any member of the same organization.
  - `reclassify` (a paid AI call) stays admin-only.
- **Services:** `CustomerService`, `ConversationService` and `TaskService` re-check the actor's organization themselves. Every ID is looked up inside the user's organization, so another tenant's is a 404, and `organization_id` from input is ignored.

## Data (`2026_10_13_000000_create_customer_workspace`)

| Table | Changes |
| --- | --- |
| `customers` | Adds `first_name`, `last_name`, `phone`, `phone_digits`, `company`, `notes`, `status`, `last_activity_at` (backfilled) and a generated `search_text` with a `pg_trgm` GIN index (skipped if the extension isn't allowed). Indexes: unique `(organization_id, email)` replaces the plain index; `(organization_id, status)`; `(organization_id, last_activity_at)`. |
| `conversations` | Adds `latest_confidence` and `latest_urgency` (backfilled), `closed_at`, `closed_reason`; index `(customer_id, last_message_at)`. |
| `messages` | Adds `read_at`; partial index for unread customer replies. |
| `message_classifications` | Adds `source`, `overridden_by`, `previous_intent`, `override_reason`. |
| `conversation_events` (new) | closed, reopened, status_changed, classification_changed |
| `tasks` | Adds `created_by`; index `(customer_id, status)`. |

**Deploying:** the new unique email index will fail the migration if an organization already has two customers with the same email. Check for that first.

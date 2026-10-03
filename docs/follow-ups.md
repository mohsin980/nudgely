# Follow-up & reminder system (Task 8)

Goal: never forget to follow up with a customer. A follow-up answers who, why, when, whether the customer already replied, whether it's done, and whether QuoteFollow may send it on its own.

## Model (`follow_ups`)

| Column | Notes |
| --- | --- |
| `organization_id`, `customer_id` | Required. Always taken from the signed-in user or the automation, never from input. |
| `conversation_id` | Optional for manual reminders; automated follow-ups always have one (it carries the Reply-To thread). |
| `automation_id`, `automation_action_id`, `automation_run_id` | Set for automated follow-ups; become null if the automation is deleted. |
| `type` | `manual` or `automated` |
| `status` | `pending`, `due`, `completed`, `cancelled`, `skipped`, `failed` |
| `due_at` | UTC. Shown in the organization's timezone. |
| `created_by`, `assigned_to`, `completed_by` | Users in the same organization |
| `completed_at`, `completion_notes` | Set on completion |
| `cancelled_at`, `cancelled_reason` | `customer_replied`, `not_interested`, `duplicate`, `manually_cancelled`, `other` |
| `skip_reason` | Exact system reason (see stop conditions) |
| `subject`, `body` | The automation's email template |
| `notes`, `outcome` | The person's notes; the latest result in plain words |
| `message_id` | The email sent for it |
| `metadata.history` | Scheduled, rescheduled, due/ready, completed, cancelled, skipped and email-sent events, each with time and user |
| `due_notified_at`, `overdue_notified_at` | Make each notification one-time |
| `idempotency_key` | One automated follow-up per automation action and event |

## Status rules (`FollowUpStatus::allowedTransitions()`)

```
pending ──► due ──► completed | cancelled | skipped | failed
   │         │
   │         └──► pending   (rescheduled)
   └──► completed | cancelled | skipped
completed / cancelled / skipped / failed: final
```

Every change goes through `FollowUpService` (or `FollowUpProcessor` for system steps). Each one:
1. locks the row with `SELECT … FOR UPDATE`;
2. checks the transition is allowed;
3. records it in the history.

Rescheduling keeps the same record and stores the old and new time in the history.

## Scheduler

| Command | Schedule | What it does |
| --- | --- | --- |
| `follow-ups:process-due` | every minute | One `UPDATE … WHERE status = 'pending' AND due_at <= now() … FOR UPDATE SKIP LOCKED RETURNING id` marks up to 500 follow-ups due and queues a `ProcessFollowUpJob` for each. No other work happens in the scheduler. |
| `follow-ups:notify-overdue` | hourly | Follow-ups still `due` 24 h after their time trigger one "is overdue" notification each. |

Production needs `php artisan schedule:work` (or cron running `schedule:run`) and a queue worker.

## Processing (`ProcessFollowUpJob` → `FollowUpProcessor::process`)

It runs inside a transaction on the locked row. If the follow-up isn't `due`, or was already handed to a person, the result is `already_processed`.

### Manual follow-ups

The follow-up stays `due`, and the assignee (or the admins) gets "John Smith's follow-up is due today."

### Automated follow-ups

**Stop conditions:** the first match skips the follow-up with that `skip_reason`.

| Condition | `skip_reason` |
| --- | --- |
| Automations turned off for the organization | `automations_disabled` |
| Automation deleted | `automation_deleted` |
| Automation not active | `automation_inactive` |
| Customer missing, or without a valid email address | `customer_unavailable` |
| Conversation missing | `conversation_missing` |
| Customer replied, in any of their conversations, after the follow-up was scheduled | `customer_replied` |
| Customer opted out | `opted_out` |
| Conversation closed | `conversation_closed` |
| Another follow-up with this customer was completed after this one was scheduled | `another_follow_up_completed` |

**Sending decision:**

| Situation | Result |
| --- | --- |
| `automatic_email_enabled = false`, `require_approval_for_email = true`, or the action requires approval | Stays `due`. The owner sees **Follow-up ready** with Send Follow-Up / Reschedule / Cancel and gets "John Smith's follow-up is ready to send." |
| An automated follow-up email reached this customer within `min_interval_hours` (24) | Skipped: `recently_followed_up` |
| Hourly or daily follow-up email limit reached | Stays `due`, marked ready, owner notified |
| Allowed | Sent (see below) |

**Sending:**
1. It runs the `AutomatedEmailPolicy` checks: verified sender, valid address, opt-out, already-sent, and the automation email rate limit.
2. It sends through `EmailService::sendToConversation()`, from the verified sender with a secure Reply-To.
3. The follow-up becomes `completed`.

A sender or template problem marks the follow-up `failed` and notifies the owner.

**Send Follow-Up** (a person approving) runs the same stop conditions, minus the automation-active requirement, and the same delivery checks. A send that can't go out leaves the follow-up `due`.

## Duplicate protection

| Layer | Protects against |
| --- | --- |
| `UPDATE … RETURNING` in the scheduler | Two scheduler runs queueing the same follow-up |
| Row lock (`FOR UPDATE`) in the job | Two workers processing it at once: the second waits, then sees it's no longer due |
| `due_notified_at` | Notifying twice |
| Unique `messages.metadata->>'automation_key'` (`sha256('follow_up|{id}')`) | A second email, even if a worker died after sending and before saving the status |
| `follow_ups.idempotency_key` | An automation scheduling the same follow-up twice |

## Customer replies

`CustomerReplyReceived` triggers `SkipFollowUpsOnCustomerReply`, which skips that conversation's open **automated** follow-ups that were created before the reply, with `customer_replied`.
- Completed or cancelled follow-ups are never touched.
- Manual reminders stay open: a person decides whether they're still needed.

The due-time check catches replies that were stored without the event.

## Screens

| Route | What |
| --- | --- |
| `/dashboard` | Follow-ups widget: overdue, due today, upcoming, plus View Follow-Ups |
| `/follow-ups` | Overdue, Due today and Upcoming (open), then Completed and Cancelled & skipped. Complete, Reschedule, Cancel, Send Follow-Up and Schedule Follow-Up. |
| `/customers/{id}` | Upcoming follow-ups, follow-up history, conversations, Schedule Follow-Up |
| `/inbox/{id}` | Follow-up panel: open follow-ups and the latest finished one (e.g. "Follow-up skipped. Reason: Customer replied before the follow-up date.") |
| `/settings/business` | Business timezone (admins) |

## Access

- `FollowUpPolicy`: any user in the organization can view, create, complete, reschedule, cancel and send its follow-ups. Nobody can touch another organization's.
- Pages look up every ID inside the user's organization; another tenant's ID is a 404.
- `FollowUpService` re-checks the actor's organization on every change.

## Timezone

- `due_at` and all timestamps are stored in UTC.
- Each organization has `timezone` (an IANA name, US zones only), set under Settings → Business.
- **When none is set**, `config('follow_ups.default_timezone')` is used: **America/Chicago** (US Central), overridable with `FOLLOW_UPS_DEFAULT_TIMEZONE`.
- Dates people type are read in the organization's timezone, and "today", "overdue" and "upcoming" use the organization's day boundaries.

## Configuration (`config/follow_ups.php`)

| Key | Env | Default |
| --- | --- | --- |
| `default_timezone` | `FOLLOW_UPS_DEFAULT_TIMEZONE` | `America/Chicago` |
| `limits.max_emails_per_hour` | `FOLLOW_UPS_MAX_EMAILS_PER_HOUR` | 10 per organization |
| `limits.max_emails_per_day` | `FOLLOW_UPS_MAX_EMAILS_PER_DAY` | 50 per organization |
| `limits.min_interval_hours` | `FOLLOW_UPS_MIN_INTERVAL_HOURS` | 24 |
| `batch_size` | | 500 per scheduler run |
| `overdue_after_hours` | | 24 |
| `max_days_ahead` | | 365 |

## Not implemented (by design)

- SMS, WhatsApp and phone calls
- AI-written follow-up messages
- booking, discounts and quote changes
- payments and refunds
- CRM pipelines and campaigns
- workflow scripting

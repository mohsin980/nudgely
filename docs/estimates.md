# Estimates / quotes (Task 11)

A lightweight estimate system that feeds the follow-up workflow:

```
Estimate sent → customer considers it → replies / views / accepts / declines
             → AI classifies replies → automation → follow-up
```

It is **not** an accounting system. There are no invoices, payments, tax jurisdictions or PDFs.

## Pages

| Page | What it does |
| --- | --- |
| `/estimates` | List: number, customer, title, total, status, created / sent / valid-until dates. Filter by status (or "Awaiting customer" = sent or viewed), customer (`?customer=`), created date range and total range. Search by number, title, customer name or email. Paginated in SQL. |
| `/estimates/create` | Builder: customer (existing only), details, line items, totals, then Save Draft or Save & Send. `?customer=ID` or `?conversation=ID` pre-fills; both are looked up in the user's organization. |
| `/estimates/{id}` | The document as the customer sees it, status and dates, activity, follow-ups, versions. Actions depend on status: Edit / Send (draft), Revise (sent, viewed, declined, expired), Cancel, Schedule Follow-Up, Copy customer link. |
| `/estimates/{id}/edit` | Drafts only. A sent estimate redirects to its page. |
| `/estimate/view/{token}` | The customer's page: no account. Accept, Decline (optional reason and note), Ask a Question (replies by email into the estimate's conversation). |

The customer page lists the customer's estimates and has Create Estimate. The conversation page shows the linked estimate (number, total, status, View Estimate). The dashboard shows estimates sent today, estimates awaiting the customer and estimates accepted today.

## Statuses

`draft → sent → viewed → accepted | declined | expired`; `cancelled` from draft, sent, viewed or expired.

- **Sent** means the email was **delivered**, not just queued. A failed email leaves the estimate a draft and the page shows "Unable to send estimate. Please try again."
- **Viewed:** the first time the customer opens the link. A signed-in member of the business opening it doesn't count.
- **Accepted / declined:** only from sent or viewed, only before "valid until". Doing it twice changes nothing and dispatches nothing.
- **Expired:** the hourly `estimates:expire` command expires sent and viewed estimates the day after "valid until" in the organization's timezone. An expired estimate is also expired as soon as the customer opens it. Accepted, declined, cancelled and draft estimates never expire.
- The database enforces consistency with CHECK constraints. For example, `accepted` requires `accepted_at`, and any sent status requires `sent_at`.

## Money

- **Storage:** amounts are `numeric(12,2)`, quantities `numeric(10,2)`, and the tax rate `numeric(6,3)`.
- **Arithmetic:** PHP works in integer cents (`App\Support\Money`), never floats.
- **Calculation:** `EstimateCalculator` is the only place amounts are calculated. Rounding is half up.

```
amount   = quantity × unit price
subtotal = Σ amounts
discount = subtotal × percent  |  fixed amount (≤ subtotal)
tax      = (subtotal − discount) × tax rate
total    = subtotal − discount + tax
```

Totals typed or tampered in the browser are never used:
- `EstimateService` recalculates on every save and again from the stored items before sending.
- The form's live totals come from the server (`EstimateService::preview()`).

**Limits:**
- quantity: more than 0, up to 99,999.99
- unit price: 0 to 9,999,999.99
- discount: 0–100% or up to the subtotal
- tax rate: 0–100%
- items: up to 50

**Currency:** stored on each estimate. The default is `USD` (`ESTIMATES_CURRENCY`); there is no organization currency setting or conversion.

## Numbers and revisions

**Numbering:**
- Numbers run `EST-1001`, `EST-1002`, … per organization.
- They come from `organizations.estimate_sequence`, incremented atomically (`UPDATE … RETURNING`).
- `unique (organization_id, estimate_number, revision)` is the final guard.

**Revising** a sent estimate creates a **new draft version** with the same number (`EST-1024-R2`), copying its items. Nothing is overwritten:
- The sent version keeps its amounts and items.
- It stays valid until the revision is **delivered**; then it is cancelled ("replaced") and its link stops working.
- A revision can't be sent if the customer already accepted any version.

## Sending

`EstimateService::send()`:
- **Checks** that:
  - the estimate is in the organization;
  - it is a draft and not already sending;
  - it has at least one item and a total above $0;
  - "valid until" hasn't passed;
  - the customer has a valid email and hasn't opted out;
  - no other version was accepted.
- **Sends:**
  - It uses the estimate's conversation, or starts one, so the customer's reply is routed back (Task 5) and classified (Task 6).
  - It queues the email through `EmailService::sendToConversation()`. The sender must be verified; the provider is never called directly.
  - Delivery happens in `SendEmailJob`, never in the Livewire request.
- **Email content:**
  - The subject and message come from a fixed template with controlled variables:
    - `{{customer.first_name}}`
    - `{{business.name}}`
    - `{{estimate.number}}`
    - `{{estimate.title}}`
    - `{{estimate.total}}`
    - `{{estimate.valid_until}}`
  - Below the message come the estimate document (`resources/views/estimates/document.blade.php`) and a button to the customer's link.
- **After delivery:** `Message`'s update hook calls `EstimateService::deliveryUpdated()`. That marks the estimate sent and records the activity (from / to), or records a failed send.

**PDF:** there is no PDF yet. `EstimateDocument` plus the `estimates.document` view are the extension point: render that view with a PDF library in a later task.

## The customer link

**The token:**
- It is a 48-character random token: no IDs, and no organization data.
- It is stored **encrypted** (to rebuild the link) and looked up by its **SHA-256 hash**.
- It is revoked (cleared) when the estimate is cancelled or replaced.

**Not found:** wrong, revoked and draft links all get the same 404 "Estimate not found.", so nothing reveals whether an estimate exists.

**The page:**
- Responses are `noindex`, `no-referrer` and `no-store`.
- Routes are rate-limited (30 requests per minute per IP).
- Accept and decline are CSRF-protected POSTs that lock the estimate row.

## Activity

Estimate activity is stored in the existing `conversation_events` table (with `estimate_id`; `conversation_id` may be empty for a draft). It therefore appears on the estimate page, the customer timeline and the conversation timeline without a second activity system:
- `estimate_created`
- `estimate_revised`
- `estimate_sent`
- `estimate_send_failed`
- `estimate_viewed`
- `estimate_accepted`
- `estimate_declined`
- `estimate_expired`
- `estimate_cancelled`
- `estimate_replaced`

## Automation (Task 7)

Events are dispatched after commit, once per change:

| Event | Automation trigger |
| --- | --- |
| `EstimateCreated` | (not a trigger: drafts are internal) |
| `EstimateSent` | `estimate_sent` (on delivery) |
| `EstimateViewed` | `estimate_viewed` |
| `EstimateAccepted` | `estimate_accepted` |
| `EstimateDeclined` | `estimate_declined` |
| `EstimateExpired` | `estimate_expired` |

- **Conditions:** the **Estimate status** condition is read when the automation runs. For example, "viewed and still viewed" won't fire after the customer accepted.
- **Email variables:** estimate variables work in send-email and follow-up templates.
  - For reply-triggered automations, the conversation's latest sent estimate is used.
  - With no estimate, the email isn't sent (the action fails safely).
- **Templates** you can install (nothing is hard-coded):
  - **Estimate Follow-Up:** sent → follow-up in 3 days.
  - **Estimate Declined:** declined → high-priority task and notification.

## Follow-ups (Task 8)

- **Linking:** Schedule Follow-Up on the estimate page pre-fills "Follow up on Estimate EST-1024" and links the follow-up to the customer, conversation and estimate (`follow_ups.estimate_id`).
- **From automations:** automated follow-ups are linked to the estimate they are about.
- **Customer replies:** a reply skips them (Task 8).
- **Accept, decline or cancel** skips the estimate's open automated follow-ups (`estimate_closed`), checked again at due time. Manual reminders stay.

## Security

- **Organization:** every query is scoped to the signed-in user's organization. Customer, conversation and estimate IDs from the browser are re-checked in `EstimateService`; `organization_id` is never read from input.
- **Policy:** `EstimatePolicy` allows members of the same organization. Routes require `can:access-organization`, and components return 404 for other organizations' estimates.
- **Errors:** messages shown to users never include provider responses or credentials.

## Known limitations

- **"Viewed" can be triggered early:** email security scanners that open links can mark an estimate viewed early.
- **No email open tracking:** provider open and delivery webhooks are not connected, so "viewed" is based on the link.
- **Currency:** one currency (USD by default); no organization setting.
- **No PDF.**
- **No line-item catalog.**
- **No organization address or phone** on the document (they aren't stored yet); the sender email is shown.

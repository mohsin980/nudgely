# AI customer reply classification

When a customer replies to a QuoteFlow email, an AI model classifies the reply's intent so the business can see at a glance who is ready to book, who is negotiating and who needs attention.

**The AI only analyzes.** It never sends email or SMS, changes quotes, books appointments, approves discounts or contacts anyone. Its output is stored as data and shown in the Inbox, nothing more.

## Flow

```
Inbound reply stored (Task 5, sender matched → status "received")
      ↓  after commit, never on the webhook request
ClassifyCustomerReplyJob  (queue; unique per request)
      ↓
CustomerReplyClassificationService
      ├── ConversationContextBuilder   minimal context (see Privacy)
      ├── CustomerReplyClassifierInterface → OpenAIReplyClassifier → OpenAI API
      ├── ClassificationOutputValidator  strict schema check of the AI output
      ├── ReplyReviewPolicy              app-owned review + confidence rules
      └── message_classifications row   (+ conversation latest_intent / needs_attention)
      ↓
Inbox: AI Insight panel, intent badges, filters, admin "Reclassify"
```

Replies held for review (unexpected sender) are not classified.

## Categories

These are the only intents allowed (`App\Enums\CustomerReplyIntent`):

```
interested, price_objection, question, ready_to_book, wants_callback,
not_interested, needs_more_information, wrong_number, complaint, spam, unclear
```

| Field | Allowed values |
| --- | --- |
| Sentiment (optional) | `positive`, `neutral`, `negative` |
| Urgency (optional) | `low`, `medium`, `high` |

UI labels and colors come from the enum, never from AI text.

## Configuration

| Variable | Description |
| --- | --- |
| `QUOTE_FLOW_AI_PROVIDER` | `openai` (default). |
| `OPENAI_API_KEY` | Server-side only. If it isn't set, attempts are recorded as failed ("not configured") and nothing else is affected. |
| `OPENAI_CLASSIFICATION_MODEL` | Default `gpt-4.1-mini`. Must support Structured Outputs (`json_schema`). |
| `OPENAI_ORGANIZATION`, `OPENAI_BASE_URL`, `OPENAI_TIMEOUT` | Optional. |
| `QUOTE_FLOW_AI_CLASSIFICATION_ENABLED` | `true` by default; set to `false` to stop queuing classifications. |

The review policy, confidence thresholds and context limits live in `config/ai.php` (`classification.*`).

## Validation

OpenAI is called with `response_format: json_schema, strict: true`, so the model is constrained to the schema. The response is then validated again by `ClassificationOutputValidator`:

- `intent` must be one of the allowed values.
- `confidence` must be a number from 0 to 1.
- `summary` must be a non-empty string of at most 300 characters.
- `sentiment` and `urgency` must be from their lists, or null.
- `requires_human_review` must be a boolean.
- No other fields are allowed.

Malformed JSON, refusals, truncated or content-filtered output, and any invalid field produce **no classification**. The attempt is stored as `status = failed` with a safe reason.

## Review policy and confidence

`ReplyReviewPolicy` is the only place these rules live:

- **Confidence levels:** high is ≥ 0.85, medium is 0.60–0.84, low is below 0.60 (`config/ai.php` thresholds).
- **`requires_human_review`** is true when any of these hold:
  - the AI asks for review;
  - the intent is one of `price_objection`, `complaint`, `unclear`, `wants_callback` (configurable);
  - confidence is low.
- **The AI can ask for review, but it can never waive it.**

The conversation's `needs_attention` flag and `latest_intent` follow the latest classified customer reply. They power the Inbox filters: All, Needs attention, Ready to book, Price objection, Questions, Not interested.

## Retries, idempotency and history

| Failure | Behaviour |
| --- | --- |
| Timeout, 429, 5xx | Transient: the attempt is recorded and the job retries (4 tries, backoff 30s, 2m, 10m; 90s timeout). |
| 400/401/403, missing key, invalid output | Permanent: recorded, no retry. |

- **Automatic classification** uses request ID `auto-<message id>` and is skipped once any classification of the message has succeeded. Duplicate jobs call the AI once.
- **Reclassify** (Inbox, admins only, 30 per organization per hour) queues a new request ID. It adds a new row and never edits or deletes old ones. The Inbox shows the newest result plus "Previous classifications".
- **Concurrency:** a partial unique index allows at most one successful row per request ID, and writes lock the message row.

## Privacy

- **What is sent:** the business display name, the customer's **first name** only, the conversation subject, the latest reply (quoted earlier emails trimmed, up to 4,000 characters) and up to 4 previous messages (up to 1,500 characters each).
- **Never sent:** email addresses, surnames, IDs, headers or metadata.
- Requests use `store: false`, so OpenAI doesn't store the completion for later retrieval. Per OpenAI's API data policy, API data is not used for training by default, but may be retained for up to 30 days for abuse monitoring. If that isn't acceptable, request Zero Data Retention for the OpenAI organization.
- Logs contain IDs, intent, confidence and failure reasons only: never prompts, customer text, raw AI output or the API key.
- The customer's text is passed to the model as JSON, and the system prompt tells the model to ignore any instructions inside it. Even a successful prompt injection could only change the stored label, which is limited to the controlled values.

## Manual verification

1. Complete the Task 4/5 setup (verified sender, inbound webhook, queue worker), then set `OPENAI_API_KEY`, run `php artisan config:clear` and `php artisan migrate`.
2. Send an estimate with `sendToConversation()` (see [email-inbound-replies.md](email-inbound-replies.md)) and reply from the customer mailbox, for example *"That's too expensive. Can you do $5,000?"*.
3. Confirm a `ClassifyCustomerReplyJob` is queued (`select * from jobs`) and processed by the worker.
4. Confirm `message_classifications` has a `succeeded` row (`price_objection`, `requires_human_review = true`).
5. Open the conversation in `/inbox`: the AI Insight panel shows the intent, confidence, summary and "Needs attention".
6. Click **Reclassify**. A second row appears, and the first is listed under "Previous classifications".
7. Dispatch the same automatic job again (`dispatch(App\Jobs\ClassifyCustomerReplyJob::automatic($message))`): no new row is added.
8. To check the failure paths, point `OPENAI_BASE_URL` at a stub that returns invalid JSON or low confidence. Confirm a `failed` row with no insight, or `requires_human_review = true`.

## Tests

`php artisan test` covers:
- the output validator (every invalid case)
- the OpenAI request shape and error mapping (with `Http::fake`)
- the review policy and thresholds
- queuing, storage, retries, idempotency, reclassification history, isolation and context minimization (with `Tests\Fakes\FakeReplyClassifier`)
- the Inbox insight panel, badges, filters and reclassify authorization

No test calls a real AI provider.

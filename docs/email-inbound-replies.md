# Inbound replies and conversations

When a customer replies to an email QuoteFlow sent, the reply arrives in the same conversation in the **Inbox**.

## Flow

```
EmailService::sendToConversation()
      ↓        creates a reply route: random token → stored as SHA-256 hash only
Customer receives email
      ↓        Reply-To: reply+<40 hex chars>@inbound.quoteflow.ai
Customer replies
      ↓        MX for inbound.quoteflow.ai → Postmark inbound
Postmark inbound webhook
      ↓        POST /webhooks/email/inbound/postmark  (HTTP Basic auth)
InboundEmailWebhookController
      ↓        authenticate → size limit → parse → store webhook_events row once → queue
ProcessInboundEmailJob → InboundEmailProcessor  (one DB transaction, event row locked)
      ↓        token → active route → organization → conversation → customer
      ↓        sender == customer email ?  attach (received) : hold for review (needs_review)
      ↓        sanitize HTML, store inbound Message, update conversation.last_message_at
Inbox → conversation thread
```

Not implemented yet: AI cleanup or classification, automatic responses, attachment processing, an email composer and a reply UI.

## Configuration

| Variable | Description |
| --- | --- |
| `QUOTE_FLOW_INBOUND_REPLY_DOMAIN` | Domain used in Reply-To addresses. Defaults to `inbound.quoteflow.ai`. |
| `POSTMARK_INBOUND_WEBHOOK_USERNAME` | Basic auth username in the webhook URL. Defaults to `postmark`. |
| `POSTMARK_INBOUND_WEBHOOK_SECRET` | Basic auth password, as a long random string. **If it isn't set, every webhook is rejected.** |
| `QUOTE_FLOW_REPLY_ROUTE_TTL_DAYS` | Optional. Reply addresses stop working after this many days (default 365; empty for never). |
| `QUOTE_FLOW_INBOUND_MAX_PAYLOAD_KB` / `QUOTE_FLOW_INBOUND_MAX_BODY_KB` | Optional. Webhook size limit (default 10 MB) and per-body truncation (default 512 KB). |

### Postmark setup

1. **DNS:** add an MX record for `inbound.quoteflow.ai` pointing to `inbound.postmarkapp.com` (priority 10).
2. **Inbound domain:** in the Postmark server's **Inbound** stream, set the inbound domain to `inbound.quoteflow.ai`.
3. **Webhook URL:** set the inbound webhook URL to the following, and enable "Include raw email content" only if you need it (it isn't used):
   ```
   https://<postmark-user>:<secret>@app.quoteflow.ai/webhooks/email/inbound/postmark
   ```
   Postmark doesn't sign inbound webhooks. Credentials in the URL (sent as HTTP Basic auth) are its supported mechanism. Optionally, also restrict the endpoint to [Postmark's webhook IPs](https://postmarkapp.com/support/article/800-ips-for-firewalls) at your firewall or load balancer.

## Security model

- **The reply token is the only source of routing.**
  - Tokens are 160-bit random lowercase hex and are never stored. The `email_reply_routes` table keeps only `token_hash` (SHA-256) and `token_last4`.
  - A route resolves only while it is active and unexpired, and only for our inbound domain.
- **No tenant data comes from the payload.** Organization, conversation and customer are looked up from the route, and each lookup is scoped to the route's organization. IDs, headers and `In-Reply-To` / `References` in the payload never select a tenant or conversation; threading headers are stored for later use only.
- **Sender validation:**
  - The reply is attached to the conversation only when `From` matches the customer's email (case-insensitive).
  - Otherwise it is stored with `status = needs_review` and no conversation, and listed under **Needs review** in the Inbox. A leaked token alone can't inject messages into a customer's thread.
  - `From` can be forged, so SPF and authentication results (`Received-SPF`, `Authentication-Results`) are kept on the normalized email for a later, stricter check.
- **Idempotency:**
  - `webhook_events` has a unique `(provider, event_type, external_event_id)`, so a retried delivery is acknowledged with 200 and not queued again.
  - Processing locks the event row in a transaction and skips finished events.
  - A partial unique index on `messages (provider, provider_message_id) WHERE direction = 'inbound'` makes a second copy impossible even under concurrency.
- **Trusted timestamps.** `received_at` (and therefore `last_message_at` and thread order) is when QuoteFlow received the webhook. The sender-controlled `Date` header is kept only as `metadata.email_date`.
- **Untrusted content:**
  - HTML is sanitized with `symfony/html-sanitizer` on arrival and again before rendering. That removes scripts, event handlers, iframes, forms, styles and images (tracking pixels), and allows only http(s)/mailto links, which get `rel="noopener noreferrer nofollow"`.
  - Plain text is always escaped. Nothing in an email causes a URL to be fetched.
- **Abuse limits:**
  - Basic auth first, then 300 requests per minute per IP.
  - Payload size limit (413) and per-body truncation.
  - Attachment **contents are dropped** before storage; only name, type and size are kept.
- **Logs** contain IDs and outcomes only: no secrets, headers, Authorization values or message bodies.

## Data

| Table | Purpose |
| --- | --- |
| `customers` | Minimal: organization, name, email. |
| `conversations` | Organization, customer, subject, status (`open` / `closed`), `last_message_at`. |
| `email_reply_routes` | Token hash → organization and conversation, with `active` and `expires_at`. |
| `webhook_events` | Sanitized payload, `processed_at` / `failed_at` / `failure_reason`. The payload includes the email body, so add a retention policy before production. |
| `messages` (extended) | Adds `conversation_id`, `email_reply_route_id`, `received_at`, `header_message_id`, `in_reply_to`, `references`. Inbound statuses are `received` and `needs_review`. |

Webhook event outcomes, recorded on the event row:

| Outcome | Event row | Effect |
| --- | --- | --- |
| attached | `processed_at` set | Message stored and threaded |
| needs review | `processed_at` set | Message stored without a conversation |
| no usable route (unknown, expired or inactive token; foreign domain; mismatched organization) | `failed_at` + reason | No message stored |
| invalid payload | `failed_at` + reason | No message stored |
| duplicate | `processed_at` set | Nothing new stored |

## Manual end-to-end test

Use a test domain and mailboxes you control.

1. Complete the sending setup in [email-sending.md](email-sending.md): verify `example.com`, use `sales@example.com` as the default sender, and run a queue worker.
2. Complete the Postmark inbound setup above. Set `POSTMARK_INBOUND_WEBHOOK_SECRET`, then run `php artisan config:clear` and `php artisan migrate`.
3. Create a customer and conversation, then send:
   ```bash
   php artisan tinker --execute='
   $org = App\Models\Organization::first();
   $customer = $org->customers()->create(["name" => "John Smith", "email" => "you@your-test-inbox.test"]);
   $conversation = new App\Models\Conversation(["subject" => "HVAC Estimate"]);
   $conversation->forceFill(["organization_id" => $org->id, "customer_id" => $customer->id])->save();
   app(App\Services\Email\EmailService::class)->sendToConversation($conversation, "Your HVAC estimate", text: "Hi John, just following up on your estimate.");'
   ```
4. In the received email, confirm `From: Dallas Cooling <sales@example.com>` and `Reply-To: reply+<40 hex chars>@inbound.quoteflow.ai`.
5. Reply from that mailbox. Postmark posts the webhook, and the worker processes it.
6. Open `/inbox`, then the conversation. The reply appears below the outbound email, styled as received.
7. In Postmark's Inbound activity, **retry** the webhook. Confirm there is still exactly one inbound message: `select count(*) from messages where direction = 'inbound'`.
8. Reply from a different mailbox. The reply shows under **Needs review** and not in the thread.
9. Run `grep -iE "secret|authorization" storage/logs/laravel.log` and confirm it finds no credentials.

## Tests

`php artisan test` covers:
- webhook authentication, validation, size limits and duplicates
- Postmark normalization
- reply routes (hashing, expiry, inactive routes, domain checks)
- processing: routing, sender validation, tenant isolation, idempotency and sanitizing
- the Inbox UI: display, distinct styling, XSS-safe rendering and organization isolation

No test calls a real provider.

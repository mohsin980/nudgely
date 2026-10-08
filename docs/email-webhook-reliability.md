# Email and webhook reliability and observability (Task 16C)

## 1. Architecture as found, and as changed

**Outbound**
```
Application (EstimateService, FollowUpProcessor, AutomationActions, Settings)
  → EmailService          checks sender, plan limit, opt-out, suppression; stores the Message as queued
  → SendEmailJob          queued after commit; retried per policy
  → EmailService::deliver claims queued → sending (atomic), checks sender again
  → EmailProviderInterface (EmailProviderManager → PostmarkEmailProvider)
  → Postmark API          explicit timeouts: 5s connect, 15s total
```
Provider responses are normalized into `EmailSendResult` and `EmailProviderException`. Postmark-specific codes do not leave the adapter.

**Inbound replies**
```
Postmark → POST /webhooks/email/inbound/postmark
  → authenticate (Basic auth, constant-time), JSON content type, size limit, parse
  → WebhookEvent stored (received, payload hash, correlation ID, sanitized payload)   ← answer returned here
  → ProcessInboundEmailJob → InboundEmailProcessor
  → reply token → EmailReplyRoute (active, unexpired) → organization and conversation (trusted)
  → Message (received, or needs_review when the sender does not match the customer)
```

**Delivery events (new)**
```
Postmark → POST /webhooks/email/events/postmark (own Basic auth credential)
  → verify, validate record type / message ID / recipient, store WebhookEvent
  → ProcessDeliveryEventJob → DeliveryEventProcessor
  → message found by provider + provider message ID; organization taken from the message
  → MessageStatus::canTransitionTo; bounce/complaint suppresses the customer's address
```

Gaps found in the audit (all addressed here): no delivery events (bounces were invisible, and bounced
addresses were emailed again); a failed inbound event was acknowledged as "already received" forever, with
no replay; webhook rows had no lifecycle, attempt count or correlation; logs had no event names or durations.

## 2. Outbound state model

| Status | Meaning | Set by |
|---|---|---|
| queued | Accepted by the app, waiting for the job | EmailService |
| sending | A worker claimed it and is calling the provider | EmailService::deliver |
| sent | Provider accepted it (provider message ID stored) | EmailService::deliver |
| delivered | Provider reports it reached the mailbox | DeliveryEventProcessor |
| bounced | Permanent bounce; the address is suppressed | DeliveryEventProcessor |
| complained | Recipient reported spam; the address is suppressed | DeliveryEventProcessor |
| failed | Not accepted (permanent rejection, or unknown outcome) | EmailService |

Allowed transitions (`MessageStatus::canTransitionTo`):
- queued → sending, failed
- sending → sent, queued (temporary failure, retried), failed
- sent → delivered, bounced, complained
- delivered → complained
- failed → delivered, bounced, complained **only** with provider evidence (documented reconciliation: a timed-out send was recorded as failed, then the provider reports it delivered)

Everything else is refused and the event is recorded as ignored with the reason.

Timestamps: `sent_at`, `delivered_at`, `bounced_at`, `complained_at`, `failed_at`, plus `send_attempts` and `correlation_id`.

## 3. Idempotency

| Operation | Mechanism |
|---|---|
| Send a queued message | Atomic claim `queued → sending`; a second job finds nothing to claim |
| Automated / follow-up email | Unique index on `messages.metadata->automation_key` (per action and event, or per follow-up) |
| Inbound email | Unique `(provider, event_type, external_event_id)` on `webhook_events`; unique inbound provider message ID |
| Delivery event | Unique `(provider, event_type, external_event_id)`, where the ID is `RecordType:ID` (bounce, complaint) or `Delivery:MessageID` |
| Delivery applied to a message | Unique `(provider, provider_message_id)` on outbound messages; transition guard; "already recorded" check |
| Replay | Processors are idempotent; a replay refuses events that are not failed |

Keys are stable: they come from the business operation (message ID, provider IDs, action and event), never a random value generated per retry.

## 4. Retry strategy

| Provider response | Class | Behaviour |
|---|---|---|
| 429, 5xx | Temporary (`unavailable`) | Back to queued; retried with backoff (30 s … 30 min, 5 tries) |
| Connection failed before the request was sent | Temporary | Same |
| Request timed out (may have been accepted) | Unknown (`outcome_unknown`) | Recorded as failed, **not resent** |
| 401, 403 | Permanent (`not_configured`) | Failed; no retry |
| 400, 422 (rejected, invalid recipient or sender) | Permanent (`rejected`) | Failed; no retry |
| Other unexpected response | Permanent (`unexpected_response`) | Failed; no retry |

Webhook processing retries (inbound and delivery): an attempt that throws leaves the event `received` with
the reason and the attempt count; the job retries (5 tries). The final failure marks it `failed` for an operator.
A delivery event that arrives before the send job recorded "sent" is retried, not dropped.

Timeouts: outbound 5 s connect, 15 s total; jobs 60 s (email), 120 s (inbound). All below the queue's `retry_after` (300 s).

## 5. Webhook event lifecycle

`received → processing → processed | ignored | failed`

- `received`: stored, not yet processed (also between retries; `failure_reason` says why the last attempt failed).
- `processing`: a worker is on it. `attempt_count` is incremented at each attempt.
- `processed`: applied.
- `ignored`: handled on purpose with nothing to do (duplicate, unknown message, unknown route, soft bounce, recipient mismatch, transition not allowed). Never retried.
- `failed`: permanently failed (invalid payload, or retries exhausted). Only an operator can replay it.

`organization_id` is set once the event is traced to a tenant: from the reply route (inbound) or the message (delivery). It is never taken from the payload.

## 6. Operator tools

- `php artisan webhooks:failed [--organization=ID]`: failed events with provider, type, organization, attempts, reason, correlation ID.
- `php artisan webhooks:replay ID [--organization=ID]`: queues a failed event again. Refuses non-failed events, and events of another organization report "not found".
- `php artisan email:trace MESSAGE_ID`: status, timestamps, attempts, failure reason, correlation ID and the delivery events for one message. Addresses and bodies are not printed.

There is no public replay endpoint.

## 7. Logging

Events (the `event` field, consistent names): `email.queued`, `email.sending`, `email.sent`, `email.failed`,
`email.retry_scheduled`, `email.delivered`, `email.bounced`, `email.complained`, `email.reconciled`,
`webhook.received`, `webhook.verified`, `webhook.rejected`, `webhook.duplicate`, `webhook.processed`,
`webhook.failed`, `webhook.ignored`, `webhook.replayed`.

Fields where known: `organization_id`, `conversation_id`, `message_id`, `webhook_event_id`, `provider`,
`provider_message_id`, `status`, `attempt`, `reason`, `correlation_id`, `duration_ms`.

Never logged: API tokens, passwords, webhook credentials, reply tokens, invitation tokens, raw webhook
signatures, email bodies, recipient addresses. Rejections carry a reason code only. Tests check this.

Correlation: the caller's `X-Request-Id` when it is a safe label, otherwise a fresh UUID. It is stored on the
message and the webhook event and appears in every log line for that operation. It is a label, not a token.

## 8. Errors shown to users

Provider response bodies, stack traces, database errors and payloads are never shown. Stored failure reasons
are generic ("The email provider did not confirm delivery. It was not resent…"). Exception classes are
logged, not stored.

## 9. Tenant isolation

- Outbound: a connection must belong to the sending organization and be verified (tested).
- Inbound: the reply token selects the organization and conversation; the payload's sender only decides whether the
  message is attached or held for review (tested).
- Delivery events: the message is found by provider ID, and the organization comes from that message (tested).
- Replay: scoped by organization; other organizations' events report "not found" (tested).

## 10. Retention (recommendation; not auto-deleted)

- Processed webhook events: 90 days (already pruned by `retention:prune`).
- Failed webhook events: keep until an operator resolves them, then 90 days. The payload may contain customer
  email content; a future prune can drop `payload` on failed events after 30 days while keeping the metadata.
- Message bodies: kept with the business record (conversation history). Not pruned here.
- Provider metadata and correlation IDs: keep with the message.

Automatic deletion beyond the existing pruning needs product approval.

## 11. Known limitations

- A timed-out send is not resent. The customer may need a manual resend (visible as failed with the reason).
- Delivery events depend on Postmark's webhook being configured with the events credential.
- Soft bounces are ignored; the provider keeps retrying and reports the final outcome.
- `email:trace` matches delivery events by a JSON field without an index. It is an operator tool, not a request path.
- No external alerting is wired in (Task 16D).

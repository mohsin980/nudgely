# Sending business email

QuoteFlow sends email **from each organization's own verified business sender**, for example `Dallas Cooling <sales@example.com>`. Sending is refused until that sender's domain is verified (see [email-domain-verification.md](email-domain-verification.md)).

Not implemented yet: inbound replies, follow-up automation, templates, scheduling and bulk email.

## Flow

```
example.com
      ↓        Settings → Email: admin adds domain + sender
DNS verification
      ↓        Verify Domain → Postmark registers domain, returns DKIM TXT + Return-Path CNAME
      ↓        Business adds records → Check Verification asks Postmark
Verified
      ↓        verification_status = verified, verified_at set; can be set as default
sales@example.com
      ↓        Organization's default EmailConnection: "Dallas Cooling" <sales@example.com>
QuoteFlow Email Service
      ↓        EmailService::send(): checks sender is verified + in domain,
      ↓        validates recipient/Reply-To, stores Message (status: queued)
Queue
      ↓        SendEmailJob (unique per message, 5 tries with backoff):
      ↓        claims message (queued → sending), re-checks verification
Postmark
      ↓        PostmarkEmailProvider::send() → POST /email with Server token
      ↓        success → Message: sent + provider_message_id
      ↓        failure → retried (timeout/5xx) or failed with safe reason
Customer
               From: "Dallas Cooling" <sales@example.com>
               Reply-To: reply+secure-token@inbound.quoteflow.ai (if supplied)
```

- **Unverified domain:** the flow stops at the Email Service with *"Your business email domain must be verified before emails can be sent."* Nothing is queued.
- **Send Test Email** (Settings → Email) skips the queue and goes straight from the Email Service to Postmark, so the admin sees the result immediately.

The domain-verification steps are described in [email-domain-verification.md](email-domain-verification.md).

## Configuration

| Variable | Description |
| --- | --- |
| `QUOTE_FLOW_EMAIL_PROVIDER` | Provider for new connections (`postmark`). |
| `POSTMARK_SERVER_TOKEN` | Postmark **Server** API token (Server → API Tokens). Used only to send email. |
| `POSTMARK_ACCOUNT_TOKEN` | Postmark **Account** API token, used for domain registration and verification. |
| `POSTMARK_MESSAGE_STREAM` | Optional. Transactional stream ID, defaults to `outbound`. |
| `QUEUE_CONNECTION` | Use a real queue (`database`, `redis`, …) in every environment that sends email, and run `php artisan queue:work`. |

Tokens stay server-side. They are never stored on models, never sent to the browser, and never logged.

## Sending from application code

Always go through `App\Services\Email\EmailService`. Never call a provider directly.

```php
$message = app(EmailService::class)->send(
    organization: $organization,      // resolve from the authenticated user / tenant, never from input
    to: $customer->email,
    subject: 'Your estimate',
    html: $html,
    text: $text,
    replyTo: $replyTo,                // optional; never invented
    toName: $customer->name,
);
```

`send()`:

1. Resolves the organization's **default** email connection. It throws `EmailSendingNotAllowedException` if there is none, if the domain isn't verified, or if the sender is outside the verified domain. These messages are safe to show to users.
2. Validates the recipient and Reply-To, strips newlines from the subject and names, and stores an outbound `Message` with status `queued`.
3. Dispatches `SendEmailJob` after the database transaction commits. The provider is never called inside the web request.

### Reply-To

`replyTo` is passed through unchanged (for example `reply+<token>@inbound.quoteflow.ai`). Generating these addresses and processing replies belongs to a later inbound-email task.

## Delivery, retries and duplicate protection

`SendEmailJob` calls `EmailService::deliver()`, which:

- **Claims the message atomically** (`queued` → `sending`). A duplicate job or a second worker finds nothing to claim, so one message never becomes two emails. The job is also `ShouldBeUnique` per message.
- **Re-checks eligibility.** The connection must still exist, belong to the organization, be verified, and still use the same sender address. A domain that became unverified after queueing is never sent from.
- **Sends** from the connection's sender name and address, then stores `provider_message_id`, `status = sent` and `sent_at`.

| Failure | Result |
| --- | --- |
| Not allowed (unverified, removed connection, sender changed) | `failed`, no retry |
| Provider rejected the email (422), bad credentials (401/403), missing token | `failed`, no retry |
| Timeout, 429, 5xx | Back to `queued`, retried up to 5 attempts with backoff of 30s, 2m, 10m and 30m. After that it is marked `failed`. |

**Limitation:** Postmark has no idempotency keys. If a request times out *after* Postmark accepted it, the retry can deliver a second copy. If a worker dies mid-send, the message stays in `sending` and is never retried, which favors at-most-once delivery. A clean-up for stale `sending` messages can come later.

## Message records (`messages` table)

These columns are stored per outbound email:

- organization and connection
- direction and channel
- provider
- from and to addresses, plus Reply-To
- subject and bodies
- metadata
- `provider_message_id`
- status
- `sent_at` / `failed_at`
- a safe `failure_reason`

Bodies are kept because the queued job sends from the stored record. A retention policy should be decided before production. Provider responses, headers and tokens are never stored.

Logs (`Email queued.`, `Email sent.`, `Email failed.`, `Email provider request failed.`) contain only IDs, status codes and reasons. They never contain tokens or message content.

## Test email (Settings → Email)

Organization admins can send a fixed test message from a **verified** connection:

- From: the connection's sender, for example `Dallas Cooling <sales@example.com>`
- Subject: `QuoteFlow test email`
- Body: "This is a test email from QuoteFlow. Your business email connection is working correctly."

The only input is the recipient. The sender and content can't be changed. Sends are limited to 5 per organization per hour, run synchronously without retries, and are recorded as messages with `metadata.type = test_email` and logged.

## Manual test procedure

Use a domain you control. Never use a customer's production domain.

1. Set `POSTMARK_ACCOUNT_TOKEN`, `POSTMARK_SERVER_TOKEN` and `QUOTE_FLOW_EMAIL_PROVIDER=postmark` in `.env`, then run `php artisan config:clear` and `php artisan migrate`.
2. Create an organization with an admin user, then sign in. (The app has no login UI yet. Create the user with tinker and authenticate however your local setup allows.)
3. Go to `/settings/email` and add the domain `example.com` (your test domain), the sender name `Dallas Cooling` and the sender email `sales@example.com`.
4. Click **Verify Domain** and add the DKIM TXT and Return-Path CNAME records at your DNS host.
5. Click **Check Verification** until the connection shows **Verified**.
6. Click **Send Test Email** and send to a mailbox you control.
7. In the received email, check:
   - **From** is `Dallas Cooling <sales@example.com>`.
   - DKIM passes for `example.com`.
8. To check Reply-To and the queue, run:
   ```bash
   php artisan tinker --execute='app(App\Services\Email\EmailService::class)->send(App\Models\Organization::first(), "you@your-inbox.test", "Reply-To check", text: "Hello", replyTo: "replies@your-inbox.test");'
   php artisan queue:work --once
   ```
   Then confirm the received email has `Reply-To: replies@your-inbox.test`.
9. Check `messages`: rows exist with `status = sent` and a `provider_message_id` that matches the message in Postmark's Activity tab.
10. Run `grep -i "token" storage/logs/laravel.log` and confirm it finds no secrets.

## Tests

`php artisan test` covers the provider payload and error mapping, `EmailService` eligibility and organization isolation, the queue job (retries, permanent failures, duplicates), and the Livewire test-email flow. All provider HTTP is faked, and `Http::preventStrayRequests()` is enabled for every test.

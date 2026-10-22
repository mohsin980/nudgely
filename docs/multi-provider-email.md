# Multi-provider email (Task 17)

Status: in progress. This file records the audit, the design and what is blocked on external setup.

## 1. Audit (what the repository does today)

| Area | Finding |
|---|---|
| Sending identity | `email_connections`: one row per sender address, with a verified **domain** (`verification_status`, `provider_domain_id`) and `is_default` (one per organization, partial unique index). |
| Sending credentials | **Global**. One Postmark server token (`config/email.php`, `POSTMARK_SERVER_TOKEN`) and one account token (domain API) serve every business. Businesses never store credentials. |
| Provider choice | `EmailService::deliver()` calls `EmailProviderManager::for($connection->provider)`. The provider is already chosen per connection, from the `EmailProvider` enum (postmark, resend, mailgun, sendgrid, custom; only postmark is implemented). |
| Queue | `SendEmailJob` (5 tries, backoff) → `EmailService::deliver()`. Claims the message atomically (`queued → sending`), counts attempts, requeues transient failures, marks permanent ones failed. Unknown outcomes (timeouts after the request left) are failed, never resent. |
| Status model | `MessageStatus`: queued, sending, sent (accepted by provider), delivered, bounced, complained, failed; inbound statuses. Delivery facts come only from provider webhooks. |
| Idempotency | Claim-by-update, partial unique index on outbound provider message IDs, a stuck-send recovery sweep. |
| Encryption | Laravel `encrypted` cast (uses `APP_KEY`) is used for `estimates.public_token`. Nothing else stores a secret. |
| Audit/logs | `EmailService::log()` writes `email.*` events with redacted context (see `docs/logging-and-monitoring.md`). |
| Tests | `tests/Feature/Email/*` and `tests/Unit/Email/*` cover send, retry, state machine, domain verification, inbound webhooks. A test helper (`tests/Pest.php`) swaps the provider with a fake. |

**Compatibility constraints**
- Postmark domain flow (`EmailProviderInterface`: register, verify, send) must not change.
- `EmailProviderManager::for()` is also used for domain removal and rejects unknown names (tested).
- Existing `email_connections` rows (postmark) must keep working without a migration of their data.

## 2. Design

**Two sender families**
1. *Domain-verified providers* (postmark today): unchanged.
2. *Account-based senders* (new): `gmail`, `microsoft`, `smtp`. The connection stores the account's own address as `sender_email` and is verified by the OAuth identity (or a successful SMTP login). No DNS records are needed.

**Storage**
- Migration adds to `email_connections`: `credentials` (encrypted array: refresh token or SMTP password), `connection_status` (`active`, `reauth_required`, `disconnected`), `status_message` (sanitized text), `last_tested_at`.
- `credentials` is encrypted with the existing `encrypted` cast and is never serialized (`$hidden`).

**Delivery**
- `EmailService::deliver()` selects the sender by family: account providers use `AccountEmailSender`, which loads the connection's credentials; everything else keeps `EmailProviderManager::for()`.
- Same claim, retry and failure rules for all providers. Account providers return `sent` only when the provider accepted the request; no delivered status is inferred.
- Revoked or expired grants (`invalid_grant`, 401 from the API) are permanent for that message, set the connection to `reauth_required`, and notify the owner (later step).

**Security**
- OAuth: authorization-code flow, PKCE (S256), a random `state` bound to the session, organization and user, one-time use, expiry, and a callback that checks the connection's organization again before saving.
- Least privilege: Gmail `gmail.send` + `openid email`; Microsoft `Mail.Send` + `User.Read` + `offline_access` + `openid email`.
- SMTP: host validated; by default hosts resolving to private, loopback or link-local addresses are refused (`email.smtp.allow_private_hosts`, default false) to prevent using the server as a probe. Passwords encrypted; error messages never include the password or raw server responses.
- Rate limits on connection tests and on connect attempts.

## 3. Phases and status

| Phase | Work | Status |
|---|---|---|
| 1 | Audit and this plan | done |
| 2 | Provider families, enum, migration, encrypted credentials | step A |
| 3 | Gmail OAuth connect, token refresh, send | step B |
| 4 | Microsoft OAuth connect, token refresh, send | step B |
| 5 | Custom SMTP: validation, test connection, send | step A |
| 6 | Settings UI: three options, status, reconnect, disconnect confirmation | step C |
| 7 | Tenant isolation and security tests | throughout |
| 8 | Reauth notification to owner; reliability tests | step D |
| 9 | Full test run | step D |
| 10 | Documentation and deployment notes | step D |

## 4. External setup required (not available in this environment)

These cannot be done from the repository. Without them the OAuth flows cannot be exercised against the real providers.

**Google**
- A Google Cloud project with the Gmail API enabled.
- An OAuth client (Web application) with the redirect URI `https://<app-domain>/settings/email/google/callback`.
- OAuth consent screen: scopes `openid`, `email`, `https://www.googleapis.com/auth/gmail.send`. Gmail send is a *restricted* scope: production use needs Google's verification and a security assessment. Until then only test users can connect.
- Environment: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`.

**Microsoft**
- A Microsoft Entra app registration with supported account types *Accounts in any organizational directory and personal Microsoft accounts* (so Outlook.com/Hotmail and Microsoft 365 both work).
- Redirect URI `https://<app-domain>/settings/email/microsoft/callback` (Web).
- Delegated permissions: `Mail.Send`, `User.Read`, `offline_access`, `openid`, `email`. Organizational tenants may require admin consent.
- Environment: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT` (default `common`).

**SMTP**
- Nothing server-side. Customers supply host, port and credentials. Gmail and Outlook SMTP need an app password where 2-step verification is on.

## 5. Known limits (to be confirmed)
- Gmail sending counts against the sender's Gmail sending limits; Google restricts API use for consumer accounts.
- Microsoft `sendMail` returns no message ID, so the stored provider message ID is empty for these sends. Bounce and delivery updates are not available from these providers.
- SMTP and account senders cannot receive delivery webhooks; their messages stay `sent` (accepted), never `delivered`.

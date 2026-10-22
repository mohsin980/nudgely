# Multi-provider email (Task 17)

Status: implemented and tested with faked provider responses. Live Google and Microsoft flows still need the external app registrations in section 4; they have not been exercised against the real providers.

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
| 1 | Audit and this plan | done (section 1) |
| 2 | Provider families, enum, migration, encrypted credentials | done |
| 3 | Gmail connect (OAuth, PKCE, state), token refresh, send | done, faked provider responses only |
| 4 | Microsoft connect (Outlook.com, Hotmail, Microsoft 365), refresh rotation, Graph send | done, faked provider responses only |
| 5 | Custom SMTP: validation, safe test before saving, STARTTLS required, send | done |
| 6 | Settings page: three options, status, reconnect, make default, disconnect confirmation | done |
| 7 | Tenant isolation and security tests | done (owner-only, cross-business refusal, forged and reused state, secrets never serialized) |
| 8 | Reauth-required status, one owner notification per account, bounded retries | done |
| 9 | Test runs | see section 6 |
| 10 | This documentation | done |

## 3a. Behaviour

**Connecting.** The owner clicks *Connect Google* or *Connect Microsoft*. The browser goes to the provider's consent screen. The provider returns a code to `/settings/email/{gmail|microsoft}/callback`. The server checks the one-time state (bound to the provider, the business and the signed-in user, valid for 10 minutes), exchanges the code with the PKCE verifier, reads the account address, and saves the connection. Denial, a forged or reused state, a different user's flow, or an unverified address saves nothing.

**Connecting SMTP.** The owner fills in host, port, encryption, login and sender name. The settings are tested first (connect and sign in). Only a successful test saves the connection. The password is encrypted at rest and never displayed again.

**Sending.** Each queued message uses its connection's own credentials. The sender address is the connected account's address. The same claim, retry and failure rules as Postmark apply.

**Status after a send.** A message becomes *sent* only when the provider accepts the request. Nothing marks it *delivered* for these providers, because they send no delivery webhooks.

**Access revoked or password rejected.** The connection moves to *reauth required*, the message fails permanently (no retry loop), and the owner and managers get one in-app notice (`email_account_needs_reconnect`). Further failures for the same account do not notify again. Reconnecting restores *active*.

**Disconnecting.** Confirmation is required. For the default sender the dialog warns that automated quotes and follow-ups will stop. Disconnecting removes the stored secret and keeps the record. For Gmail, the grant is also revoked at Google; if Google cannot be reached, the page says so. For Microsoft, the page asks the business to remove QuoteFlow from its Microsoft account's app access. Deleting a Gmail account revokes it the same way. A disconnected account cannot send.

**Retries.** Transient provider errors (timeouts, 429, 5xx, unreachable servers) retry under the existing job policy. Rejections and revoked access do not retry. Unknown outcomes after a request left are not resent.

## 3b. Environment variables

| Variable | Purpose |
|---|---|
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google OAuth client. Leave empty to hide *Connect Google*. |
| `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET` | Microsoft Entra application. Leave empty to hide *Connect Microsoft*. |
| `MICROSOFT_TENANT` | Defaults to `common` (personal and work accounts). |
| `EMAIL_OAUTH_TIMEOUT` | Seconds for provider calls. Default 15. |
| `QUOTE_FLOW_SMTP_ALLOW_PRIVATE_HOSTS` | Default `false`. Only for a local development relay. Enables private addresses and unencrypted SMTP. Never enable in production. |

Redirect URIs, which must match exactly in both consoles:
- `https://<app-domain>/settings/email/gmail/callback`
- `https://<app-domain>/settings/email/microsoft/callback`

## 3c. Token storage and rotation

- Refresh tokens and SMTP passwords are stored in `email_connections.credentials` with Laravel's `encrypted` cast, which uses `APP_KEY`. The model hides the column from serialization.
- Access tokens are not stored. They are requested from the refresh token each time they are needed.
- Microsoft rotates refresh tokens. A rotated token is saved on the next use.
- Rotating `APP_KEY` makes stored credentials unreadable. Plan a re-encryption step before rotating it, or have businesses reconnect.
- Disconnecting or deleting a Gmail account revokes its grant at Google. Microsoft offers no revocation for this sign-in, so the business removes QuoteFlow from its Microsoft account's app access. SMTP has no grant to revoke, so change or remove the app password at the mail provider.

## 4. External setup required (not available in this environment)

These cannot be done from the repository. Without them the OAuth flows cannot be exercised against the real providers.

**Google**
- A Google Cloud project with the Gmail API enabled.
- An OAuth client (Web application) with the redirect URI `https://<app-domain>/settings/email/gmail/callback`.
- OAuth consent screen: scopes `openid`, `email`, `https://www.googleapis.com/auth/gmail.send`. Gmail send is a *restricted* scope: production use needs Google's verification and a security assessment. Until then only test users can connect.
- Environment: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`.

**Microsoft**
- A Microsoft Entra app registration with supported account types *Accounts in any organizational directory and personal Microsoft accounts* (so Outlook.com/Hotmail and Microsoft 365 both work).
- Redirect URI `https://<app-domain>/settings/email/microsoft/callback` (Web).
- Delegated permissions: `Mail.Send`, `User.Read`, `offline_access`, `openid`, `email`. Organizational tenants may require admin consent.
- Environment: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT` (default `common`).

**SMTP**
- Nothing server-side. Customers supply host, port and credentials. Gmail SMTP needs a Google app password when 2-step verification is on; Connect Google is the safer option. For Outlook.com or Hotmail, prefer Connect Microsoft. SMTP sign-in for personal Microsoft accounts is not verified in this repository.

## 5. Known limits

- The Gmail API scope is restricted. Production use for arbitrary Gmail users needs Google's verification and a security assessment. Until then only test users can connect.
- Gmail and Microsoft sending counts against the sender's own sending limits.
- Microsoft has no revocation step in this flow. Disconnecting leaves the grant in place until the business removes the app in its Microsoft account.
- `encryption = none` is accepted only when `QUOTE_FLOW_SMTP_ALLOW_PRIVATE_HOSTS` is on, so that flag also permits unencrypted SMTP. Keep it off in production.
- Microsoft `sendMail` returns no message ID. The record stores a local ID. Bounces and delivery updates are not available from Gmail, Microsoft or SMTP.
- SMTP senders must send as the login address. Aliases are not supported yet.
- The SMTP private-address check runs before connecting. A DNS name that changes its address between the check and the connection is not re-checked.
- A transient failure after the provider accepted a message (for example a 5xx returned after acceptance) can cause a resend. The same rule exists for Postmark.
- None of these flows has been run against live Google, Microsoft or customer SMTP servers in this repository.

## 5a. Deployment and rollback

1. Create the Google and Microsoft app registrations (section 4) and set the variables in section 3b.
2. Deploy with `php artisan migrate`. The migration only adds nullable and defaulted columns; existing Postmark connections keep working.
3. Run `php artisan config:cache` after the variables are set, and restart queue workers so they read the new config.
4. Check *Email settings* as an owner: connect a test account, send one test email, disconnect it.
5. **Rollback:** unset the OAuth variables to hide the new options (existing Postmark sending is unaffected). To remove the schema, run `php artisan migrate:rollback --step=1`. Rolling back drops stored credentials, so disconnect accounts first if they must be kept.

## 5b. Queue and worker requirements

- Sends run through `SendEmailJob` on the existing queue. Workers need outbound HTTPS to Google and Microsoft endpoints and outbound SMTP ports (587 and 465) to customer mail servers.
- Retry and failure rules are unchanged (see section 3a).

## 5c. Known test coverage

- SMTP: validation, safe error categories, refusal of private addresses and unencrypted SMTP, password never shown, owner-only, reconnect updates the same record.
- OAuth: PKCE and scopes, success, denial, forged, reused and foreign state, unverified address, refresh and send, revoked access, Microsoft rotation, notice sent once.
- Settings UI: options shown or hidden by configuration, SMTP connect and failure, disconnect confirmation, reauth status, staff and cross-business refusal.
- Gmail sending counts against the sender's Gmail sending limits; Google restricts API use for consumer accounts.
- Microsoft `sendMail` returns no message ID, so the stored provider message ID is empty for these sends. Bounce and delivery updates are not available from these providers.
- SMTP and account senders cannot receive delivery webhooks; their messages stay `sent` (accepted), never `delivered`.

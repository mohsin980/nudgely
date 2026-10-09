# Email domain verification

QuoteFlow sends automated emails from each business's own domain (for example `sales@example.com`). Before a domain can be used, the business proves it controls the domain by publishing DNS records issued by our transactional email provider.

This document covers provider setup, the verification flow, and testing. Sending email is not implemented yet.

## Configuration

| Variable | Required | Description |
| --- | --- | --- |
| `QUOTE_FLOW_EMAIL_PROVIDER` | yes | Provider used for new email connections. Currently only `postmark` is supported. Defaults to `postmark`. |
| `POSTMARK_ACCOUNT_TOKEN` | yes | Postmark **Account** API token, used by the Domains API. |
| `POSTMARK_API_URL` | no | Defaults to `https://api.postmarkapp.com`. |
| `POSTMARK_TIMEOUT` | no | Request timeout in seconds. Defaults to `15`. |
| `POSTMARK_RETURN_PATH_SUBDOMAIN` | no | Subdomain used for the custom Return-Path (bounce) domain. Defaults to `pm-bounces`, giving `pm-bounces.example.com`. |

Settings live in `config/email.php`. Credentials stay on the server: they are never stored on `email_connections`, never sent to the browser, and never written to logs.

If the token is missing or invalid, the settings page still works. Verification actions show *"Domain verification isn't available right now. Please contact support."* and a warning is logged without the token.

### Setting up Postmark

1. In Postmark, open **Account → API Tokens** and copy the **Account API token**. A *Server* token cannot manage domains. It will be needed separately once sending is implemented.
2. Set `POSTMARK_ACCOUNT_TOKEN` in the environment (never commit it).
3. Run `php artisan config:clear` (or `config:cache` in production).

## How verification works

1. An organization admin opens **Settings → Email** (`/settings/email`) and adds a business email, for example `sales@example.com` on `example.com`. It starts as **Pending Verification**.
2. **Verify Domain** registers `example.com` with Postmark, stores the provider domain ID, and shows the DNS records to publish.
3. The business adds the records at its DNS host. QuoteFlow never changes DNS.
4. **Check Verification** asks Postmark to check DKIM and the Return-Path. The connection becomes **Verified** (`verified_at` set) only when Postmark confirms **both** records. Otherwise it stays pending and the page shows which records have not been detected yet.
5. Only verified connections can become the organization's default sender.

### DNS records businesses must add

| Type | Name (example) | Value | Purpose |
| --- | --- | --- | --- |
| `TXT` | `20261001120000pm._domainkey.example.com` | `k=rsa;p=MIGf…` (issued by Postmark) | DKIM signing key |
| `CNAME` | `pm-bounces.example.com` | `pm.mtasv.net` | Return-Path / bounce handling |

The exact names and values are generated per domain and shown on the settings page with copy buttons. Some DNS hosts append the domain automatically. In that case only the part before `.example.com` should be entered. DNS changes can take some time to propagate.

### Rules worth knowing

- **Idempotent registration.** A connection that already has a `provider_domain_id` is never registered again. Connections in the same organization on the same domain share one provider domain.
- **Tenant isolation.** A domain already registered by another organization cannot be claimed; the user is asked to contact support. If Postmark already has the domain but no QuoteFlow connection references it (for example after a crash), it is deleted and re-created so new DKIM keys are issued and nobody inherits an old verification.
- **Editing.** Changing a connection's domain or sender email resets it to pending and clears `provider_domain_id`, `dns_records` and `verified_at`. QuoteFlow then tries to remove the old provider domain if no other connection uses it. If that removal fails, it is logged and does not affect the local data.
- **Deleting** a connection removes its provider domain once nothing else uses it.
- **Failures.** A rejected domain marks the connection **Verification Failed** with a safe message. Timeouts, rate limits and 5xx errors leave the status unchanged and ask the user to retry. A domain that no longer exists at the provider is reset so it can be registered again. Provider responses and exception details are never shown to users.

## Architecture

- `App\Contracts\Email\EmailProviderInterface`: `registerDomain`, `getDomainDnsRecords`, `verifyDomain`, `removeDomain`. These return provider-neutral results from `App\Services\Email\Data`.
- `App\Services\Email\EmailProviderManager`: resolves a provider by name (`email.provider`, or a connection's stored `provider`).
- `App\Services\Email\Providers\PostmarkEmailProvider`: the only Postmark-specific code. It uses Laravel's HTTP client.
- `App\Services\Email\EmailDomainService`: registration, verification and cleanup logic, used by the Livewire page.
- `App\Exceptions\Email\EmailProviderException`: carries a technical message for logs and a safe `userMessage()` for the UI.

### Adding another provider

1. Implement `EmailProviderInterface`, mapping the provider's responses into `DnsRecord` / result objects and its errors into `EmailProviderException`.
2. Add a `create{Name}Driver()` method to `EmailProviderManager` and a config block in `config/email.php`.
3. The `EmailProvider` enum already lists `resend`, `mailgun`, `sendgrid` and `custom`.

## Tests

```bash
php artisan test
```

The tests never reach a real provider: `Tests\TestCase` calls `Http::preventStrayRequests()`, Postmark is exercised with `Http::fake()`, and the service is tested against `Tests\Fakes\FakeEmailProvider`. The tests use PostgreSQL (`quoteflow_testing`; see `phpunit.xml`).

# WhatsApp Business integration

Each business connects its own WhatsApp Business number through Meta's Embedded Signup. QuoteFlow receives
messages on that number, matches them to customers by phone number, and sends replies from it.

## Messaging rules

- **Inside 24 hours** of the customer's last message: free-form text (`WhatsAppMessagingService::sendText`).
- **Outside 24 hours**: only an approved template (`sendTemplate`, config `whatsapp.follow_up_template`).
  The template must already be approved in the business's WhatsApp Manager.
- Sends are queued (`SendWhatsAppMessageJob`). Transient Meta failures retry; rejected messages are marked failed with a reason.
- Delivery updates (sent, delivered, read, failed) move the message forward only, through `MessageStatus::canTransitionTo`.

## Configuration

Set in `.env` (see `.env.example`). Nothing is sent to Meta and the webhook returns 404 until `WHATSAPP_ENABLED=true`
and the app credentials are set.

| Variable | Purpose |
| --- | --- |
| `WHATSAPP_ENABLED` | Master switch. |
| `WHATSAPP_APP_ID`, `WHATSAPP_APP_SECRET` | The platform's Meta app. The secret verifies every webhook signature. Never commit it. |
| `WHATSAPP_SIGNUP_CONFIG_ID` | The Embedded Signup configuration from the Meta app dashboard. |
| `WHATSAPP_VERIFY_TOKEN` | Any long random string; enter the same value in Meta when subscribing the webhook. |
| `WHATSAPP_GRAPH_VERSION` | Graph API version. Check Meta's changelog before upgrading. |
| `WHATSAPP_FOLLOW_UP_TEMPLATE`, `..._LANGUAGE` | Name and language of the approved follow-up template. |

Run `php artisan webhooks:check` to see whether the WhatsApp credentials are present (values are never printed).

## Meta setup (done by the platform owner, once)

1. Create a Meta app with the WhatsApp product. Complete business verification.
2. Request advanced access to `whatsapp_business_messaging` and `whatsapp_business_management` (App Review).
3. Create an Embedded Signup configuration and copy its ID to `WHATSAPP_SIGNUP_CONFIG_ID`.
4. Set the webhook callback to `https://<your-domain>/webhooks/whatsapp`, the verify token above, and subscribe to the `messages` field.

## Webhooks and data

- `GET /webhooks/whatsapp` answers Meta's verification challenge.
- `POST /webhooks/whatsapp` requires a valid `X-Hub-Signature-256`. The raw body is stored as a `WebhookEvent` (`event_type` = `whatsapp`) before processing, and duplicates are acknowledged without being stored twice.
- The `phone_number_id` in a payload selects the organization through `whatsapp_connections`. Customers are matched by `phone_digits` inside that organization only.
- A message from an unknown number is stored with status `needs_review` and is not attached to a customer.
- Access tokens are encrypted at rest and never returned to the browser.

## Known limits

- Customer phone numbers are matched as digits only. Store them with the country code (for example `+92 300 1234567`); a local number such as `0300…` will not match an international one.
- Message content is stored as text. Media messages are stored as a placeholder such as `[image message]`.
- The inbox reply box still sends email only. WhatsApp sending is available through `WhatsAppMessagingService` and needs a UI before it is used by businesses.
- Meta's rate card and template prices were not verified against Meta's pages while this was built.

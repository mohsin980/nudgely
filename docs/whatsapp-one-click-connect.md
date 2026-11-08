# One-click WhatsApp connect (Meta Embedded Signup)

Businesses connect their WhatsApp number from Settings → WhatsApp with **Continue with Facebook** instead of
creating their own Meta app and pasting IDs, tokens and a webhook. This uses QuoteFlow's own Meta app.
The manual method stays available under "Connect manually with your own Meta app (advanced)".

## What happens when a business connects

1. Meta's popup (Facebook JS SDK, `FB.login` with the Embedded Signup configuration) lets them sign in, pick or
   create a business portfolio and WhatsApp Business account (WABA), and add and verify their number.
2. The popup reports the WABA ID and phone number ID to the page (`WA_EMBEDDED_SIGNUP` message) and `FB.login`
   returns a one-time code. Both go to `WhatsAppSettings::completeEmbeddedSignup`.
3. `WhatsAppConnectionService::completeEmbeddedSignup` (server side, nothing from the browser is trusted):
   - exchanges the code for a business token (`GET /oauth/access_token` with the app secret);
   - checks the phone number belongs to that WABA (`GET /{waba}/phone_numbers`);
   - subscribes QuoteFlow's app to the WABA's webhooks (`POST /{waba}/subscribed_apps`);
   - registers the number for the Cloud API with a random 6-digit PIN that is stored encrypted (`POST /{phone}/register`);
   - saves the connection (`connected_via = embedded_signup`, token encrypted) and checks it with Meta.
4. Webhooks for these numbers arrive at `/webhooks/whatsapp`, signed with **QuoteFlow's** app secret.
   Disconnecting unsubscribes the app from the WABA.

## One-time setup in Meta (platform owner)

1. **Business verification** of QuoteFlow's business portfolio (Business Settings → Security Center).
2. A Meta app of type **Business** with the **WhatsApp** product, and **Facebook Login for Business**.
3. App review / advanced access for `whatsapp_business_management` and `whatsapp_business_messaging`
   (and become a **Tech Provider** so other businesses can onboard).
4. Facebook Login for Business → **Configurations** → create a WhatsApp Embedded Signup configuration.
   Its ID is `META_WHATSAPP_CONFIG_ID`.
5. Allowed domains: add the app's domain to the app's JavaScript SDK allowed domains and valid OAuth redirect URIs.
6. WhatsApp → Configuration → **Webhook**: callback `https://<app domain>/webhooks/whatsapp`, verify token
   `META_WEBHOOK_VERIFY_TOKEN` (any long random string), subscribe to **messages**.
7. Set the environment variables:

| Variable | From |
| --- | --- |
| `META_APP_ID` | App settings → Basic → App ID |
| `META_APP_SECRET` | App settings → Basic → App secret |
| `META_WHATSAPP_CONFIG_ID` | Facebook Login for Business → Configurations |
| `META_WEBHOOK_VERIFY_TOKEN` | A long random string you choose (same as in step 6) |

The button only appears when `META_APP_ID`, `META_APP_SECRET` and `META_WHATSAPP_CONFIG_ID` are all set.

## Security notes

- Settings → WhatsApp is the only page whose Content-Security-Policy allows Meta's SDK and frames, and whose
  `Cross-Origin-Opener-Policy` is `same-origin-allow-popups` (needed for the popup to report back).
  Pages reached by in-app navigation reload once so these headers apply.
- Tokens, the PIN and app secrets are encrypted at rest and never sent to the browser.
- Only the business owner (`manage-email`) can connect or disconnect; attempts are rate limited.

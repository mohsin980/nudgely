# Facebook and Instagram Lead Ads

Businesses connect the Facebook Page that runs their lead ads (Settings → Lead Ads). Each form submission, on
Facebook or Instagram, becomes a customer within seconds and starts the business's "Customer created"
automations. It uses the same QuoteFlow Meta app as one-click WhatsApp connect.

## Flow

1. **Connect.** The owner presses *Connect Facebook Page*; Facebook's sign-in returns a short-lived user token.
   The server (`LeadAdsService::pagesFor`) checks it was issued to QuoteFlow's app (`debug_token`), exchanges it
   for a long-lived token, and lists the Pages (`me/accounts`). Page tokens are cached server side for ten
   minutes under a random key; the browser only sees names and IDs. The owner ticks Pages; each is subscribed to
   the `leadgen` field (`POST /{page}/subscribed_apps`) and saved with its token encrypted. A Page can belong to
   one business only.
2. **Receive.** Meta posts to `/webhooks/meta/leads` (signed with QuoteFlow's app secret). Each `leadgen` change is
   stored once by Meta's lead ID (`lead_ads_leads`) and `ImportLeadAdJob` is queued.
3. **Import.** The job fetches the answers (`GET /{lead}` with the Page token), then finds the customer by email or
   phone, or creates one (within the plan's customer limit). Every other answer goes into the customer's notes.
   New customers fire `CustomerCreated` (automations); the handler, owner and managers get a "New lead from your
   Instagram lead ad …" notification.
4. **Safety net.** `lead-ads:sync` (every 15 minutes) lists recent leads of each Page's forms and imports any the
   webhook missed, and retries pending ones. Leads of a suspended business wait until it is reactivated.

Per Page the owner chooses who handles new leads, and whether the form tells people they'll be contacted on
WhatsApp (then leads with a phone number count as opted in to WhatsApp, so automations can message them there).

## One-time setup in Meta (platform owner)

1. Use the QuoteFlow Meta app from `docs/whatsapp-one-click-connect.md` (business verification done).
2. Add **Facebook Login for Business** (or Facebook Login) and **Webhooks**.
3. App review / advanced access for `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`,
   `pages_manage_ads` and `leads_retrieval`.
4. Webhooks → object **Page** → callback `https://<app domain>/webhooks/meta/leads`, verify token
   `META_WEBHOOK_VERIFY_TOKEN`, subscribe to the **leadgen** field.
5. Optional: a Facebook Login for Business configuration with those permissions; its ID is
   `META_LEAD_ADS_CONFIG_ID`. Without it the permissions are requested directly.
6. Businesses must give QuoteFlow's app access in their Page's **Leads Access** settings (Meta Business Suite →
   Settings → Leads access) if they restrict it.

Testing: Meta's *Lead Ads Testing Tool* (developers.facebook.com/tools/lead-ads-testing) creates test leads for a
connected Page.

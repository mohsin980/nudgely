# QuoteFlow: Pricing Package

**Status: proposed.** The plan prices below are the values already in `config/billing.php`. The add-on prices are new proposals and need the owner's approval before they go on the public site or into Stripe. Prices are billed monthly, in USD or in PKR (see section 0). There is no annual billing yet.

The single source of truth is `config/billing.php`. Change prices there, not in the views or the docs. Then update this page to match.

---

## 0. Currency

- **Visitors from Pakistan** (country code `PK`) see and pay in **PKR**. Everyone else sees and pays in **USD**.
- The country comes from the CDN in front of the app (Cloudflare `CF-IPCountry` or CloudFront `CloudFront-Viewer-Country`). No third-party lookup is made, and the IP address is not stored for this purpose. Without a configured CDN header, every visitor sees USD.
- The currency is chosen on first visit and kept for the session, so the price a visitor saw is the price they pay.
- A business keeps **one currency for life**. Its first subscription sets it, because the payment provider locks a customer to the currency of its first charge. Plan changes stay in that currency. A business that subscribed in USD is billed in USD if it later visits from Pakistan.
- Amounts are displayed with two decimal places in both currencies (for example "PKR 8,000.00" and "$22.00"). The amounts below are written without decimals for readability.
- The PKR amounts are **proposals** based on an approximate rate of PKR 280 to USD. Review them against the current rate before launch. Stripe must also be able to charge PKR for the account; confirm this in Stripe before enabling PKR checkout.

Configuration: `billing.currencies` (supported currencies and country rules) and `billing.geo.country_headers` (set `BILLING_COUNTRY_HEADERS=CF-IPCountry` only when the CDN strips any copy the visitor sends).

---

## 1. Plans

| | **Free** | **Starter** | **Pro** |
|---|---|---|---|
| Price (USD) | $0 / month | **$22 / month** | **$79 / month** |
| Price (PKR) | PKR 0 / month | **PKR 8,000 / month** | **PKR 22,000 / month** |
| Trial | — | 14 days, no card (sign-up trial) | 14 days on first paid subscription |
| Customers | 100 | 500 | 2,000 |
| Automations | 3 | 15 | 50 |
| Team members | 1 | 3 | 10 |
| Outbound emails / month | 500 | 2,500 | 10,000 |
| Estimates / month | 50 | 250 | 1,000 |
| AI assistant conversations / month | 0 | 100 | 300 |

**Features**

| Feature | Free | Starter | Pro |
|---|:---:|:---:|:---:|
| Estimates | ✓ | ✓ | ✓ |
| Automatic follow-ups | ✓ | ✓ | ✓ |
| Automations | ✓ | ✓ | ✓ |
| AI reply sorting | ✓ | ✓ | ✓ |
| AI WhatsApp assistant | — | ✓ | ✓ |
| Team roles & permissions | — | ✓ | ✓ |
| Custom branding | — | ✓ | ✓ |
| Priority support | — | — | ✓ |

**Who each plan is for**
- **Free:** a sole trader who wants to try estimates and follow-ups.
- **Starter:** a small team that needs the WhatsApp AI assistant and several people on the account.
- **Pro:** a larger business with many customers, more staff, and priority support.

---

## 2. Add-ons

Add-ons are optional extras bought on top of a plan. They never replace the plan. Each add-on adds to the plan's limits (it does not reset them) and only appears on the plans listed.

| Add-on | Price (USD) / month | Price (PKR) / month | Adds | Available on |
|---|---:|---:|---|---|
| **AI WhatsApp assistant** | **$19** | **PKR 5,000** | Turns on the AI assistant and gives 100 AI conversations a month | Free |
| **+200 AI conversations** | $12 | PKR 3,500 | 200 more AI conversations a month | Starter, Pro |
| **+1,000 customers** | $10 | PKR 2,800 | 1,000 more customers | Free, Starter, Pro |
| **+5 team members** | $10 | PKR 2,800 | 5 more people, each with their own role | Free, Starter, Pro |
| **+10,000 emails a month** | $8 | PKR 2,200 | 10,000 more outbound emails a month | Free, Starter, Pro |
| **Custom branding** | $9 | PKR 2,500 | Logo and colours on emails and estimates | Free (already included in Starter and Pro) |

**Example combinations**

| Business | Monthly cost | What they get |
|---|---:|---|
| Free + AI WhatsApp assistant | $19 | Free features, plus the assistant with 100 conversations |
| Starter + AI conversations pack | $41 | 300 AI conversations a month |
| Starter + team pack (+5) | $39 | 8 team members |
| Pro + customers pack | $89 | 3,000 customers |

---

## 3. Limits and what happens at the limit

- When a plan limit is reached, the action is blocked with a plain message, and the owner is told which limit was hit. Nothing is deleted.
- Monthly limits reset with the billing period (the calendar month if there is no subscription).
- The platform's usage monitor warns when a business reaches 80% of a limit (`BILLING_USAGE_WARNING_PERCENT`). Whether owners see the same warning on their own billing page was not checked.
- A failed payment keeps the plan for 7 days (the grace period), then paid-only limits stop applying. Data is never deleted.

---

## 4. How add-ons are defined

Add-ons are in `config/billing.php` under `addons`:

```php
'ai_assistant' => [
    'name' => 'AI WhatsApp assistant',
    'price_cents' => 1900,
    'interval' => 'month',
    'provider_price_id' => env('STRIPE_PRICE_ADDON_AI_ASSISTANT'),
    'applies_to' => ['free'],          // plans this add-on can be bought with
    'features' => ['ai_assistant'],    // features switched on while active
    'limits' => ['ai_conversations' => 100], // added to the plan's limit
],
```

`App\Billing\AddonCatalog` reads these definitions. `validate()` reports any add-on that would be wrong to sell: an unknown plan, feature or limit, a price that is not positive, or an add-on that changes nothing. `tests/Feature/Billing/AddonCatalogTest.php` runs this check, so a broken price fails the build.

---

## 5. Stripe setup

Stripe needs one product and one monthly price for each plan and each add-on.

1. In the Stripe dashboard, create a product for each plan and each add-on, with a recurring monthly price in USD matching the amounts above.
2. Copy each price ID (starts `price_`) into the server environment:

```
STRIPE_PRICE_STARTER=price_...
STRIPE_PRICE_PRO=price_...
STRIPE_PRICE_ADDON_AI_ASSISTANT=price_...
STRIPE_PRICE_ADDON_AI_CONVERSATIONS=price_...
STRIPE_PRICE_ADDON_CUSTOMERS=price_...
STRIPE_PRICE_ADDON_TEAM_MEMBERS=price_...
STRIPE_PRICE_ADDON_OUTBOUND_EMAILS=price_...
STRIPE_PRICE_ADDON_CUSTOM_BRANDING=price_...

# PKR prices (one per plan and add-on, in PKR, not USD)
STRIPE_PRICE_STARTER_PKR=price_...
STRIPE_PRICE_PRO_PKR=price_...
STRIPE_PRICE_ADDON_AI_ASSISTANT_PKR=price_...
STRIPE_PRICE_ADDON_AI_CONVERSATIONS_PKR=price_...
STRIPE_PRICE_ADDON_CUSTOMERS_PKR=price_...
STRIPE_PRICE_ADDON_TEAM_MEMBERS_PKR=price_...
STRIPE_PRICE_ADDON_OUTBOUND_EMAILS_PKR=price_...
STRIPE_PRICE_ADDON_CUSTOM_BRANDING_PKR=price_...

# Which CDN header gives the visitor's country (leave empty to show USD to everyone)
BILLING_COUNTRY_HEADERS=CF-IPCountry
```

3. Run `php artisan optimize:clear` after changing environment values.

Do not put price IDs or secret keys in code or in this document.

---

## 6. What is built and what is not

| Part | Status |
|---|---|
| Currency by visitor country (USD, PKR) | Built: `BillingCurrency`, session pinning, PKR prices on plans and add-ons, PKR checkout and plan changes |
| Subscription currency stored | Built (`subscriptions.currency`, migration `2026_11_12_000000`) |
| Plan definitions, limits and features | Built (`config/billing.php`) |
| Add-on definitions and validation | Built (`AddonCatalog`, tests) |
| Add-on purchase (Stripe checkout) | **Not built** |
| Add-ons on a subscription (Stripe items) | **Not built.** Blocker: `StripeBillingProvider::map()` takes the *first* subscription item as the plan. Add-on items must be recognised separately first, or a subscription with an add-on fails with "a price QuoteFollow doesn't know". |
| Add-on limits and features applied to a business | **Not built.** `EntitlementService` reads only the plan today. |
| Add-ons on the billing page | **Not built** |
| Annual prices | **Not built** |

**Recommendation:** do not sell add-ons until the first two rows are done. Until then, the proposed prices are for review only.

---

## 6a. Managing packages in the admin panel

Plans and add-ons are edited in the admin panel (**Billing → Plans** and **Billing → Add-ons**). Anyone with the *manage plans* permission can use them; everyone with *view plans* can see them.

**Plans** (Billing → Plans)
- **New plan:** key (permanent), name, description, sold or not, USD and PKR prices, Stripe price IDs, features, and limits (empty means unlimited).
- **Edit:** the same fields, for any plan. A reason is required and is written to the audit log.
- **Reset to configured:** only for plans that come from `config/billing.php`. It puts the configured values back.

**Add-ons** (Billing → Add-ons)
- **New add-on:** key, name, description, sold or not, the plans it can be bought with, USD and PKR prices, Stripe price IDs, features switched on, and the extra limits it adds.
- **Edit:** the same fields.

**What is checked on every save**
- Prices are whole amounts in minor units; USD is required; an add-on's price must be above zero.
- A Stripe price ID starts with `price_` and is not used by another plan or add-on.
- When Stripe is the billing provider, each new or changed Stripe price is fetched from Stripe. It must exist, be active, bill monthly, and match the currency and amount entered. A mismatch is refused, so a business is never charged something other than the price shown.
- Features must exist; limits must be whole numbers or empty.
- The default plan cannot be turned off.

**Changing a Stripe price.** A Stripe price's amount cannot be changed, so a new price means a new price ID. The old ID is kept as a *legacy* ID, so businesses already on it stay on their plan. Old IDs are never deleted.

**Turning a plan off.** An inactive plan is not sold. Nobody can start a checkout on it or move to it. Businesses already on it keep it.

**Where the values live.** Saved packages are stored in the database (`plan_settings`, with a `kind` of plan or add-on). A package with no saved row uses its values from `config/billing.php`. Saving a package stores all of its values, so later config changes to that package do not apply until it is reset.

## 6b. Custom solutions

The plans page ends with a **Need something custom? Talk to support** card. It is for businesses whose needs no plan covers, such as call coverage only, a custom package, or a larger volume. The button opens an email to `SUPPORT_EMAIL`. If that setting is empty, the card shows the text without a link.

## 7. Decisions needed from the owner

1. Confirm the Starter ($22) and Pro ($79) prices, or change them in `config/billing.php`.
2. Approve or change each add-on price above.
3. Decide whether the Free plan should include the AI assistant trial, or only sell it as the add-on.
4. Decide whether to offer annual billing, and at what discount.
5. Decide on a founding-customer price (a fixed price for early customers, with a limited number of spots), which was proposed earlier and is not built.

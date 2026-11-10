# QuoteFlow: Application Audit

**Purpose of this document:** a plain record of what has been built in QuoteFlow, why each part was built, what it is for, and where its status stands. It is written for audit and review.

**Companion document:** step-by-step workflows with diagrams are in `docs/APP_WORKFLOWS.md`.

**Scope:** the `main` branch of `mohsin980/nudgely` (245 non-merge commits at the time of writing), plus the branches described in section 12.

**How to read each item:** every area has four parts.
- **What we did:** the work, in plain terms.
- **Why:** the reason it was needed.
- **Purpose:** what it does for the business or the platform.
- **Status and references:** whether it is live, and where the detailed doc or code is.

---

## 1. Product summary

QuoteFlow is a web app for trade and service businesses (plumbing, HVAC, electrical, cleaning, and similar). It helps a business:

- create and send estimates (quotes),
- follow up with customers who have not replied,
- keep one inbox for email and WhatsApp conversations,
- run automations (for example, "when a lead is created, send a message"),
- capture leads from Facebook and Instagram ads,
- answer customers on WhatsApp with an AI assistant, and hand over to a person when needed,
- manage a team with roles and permissions, and
- pay for the service through a plan with usage limits.

**Why this product:** small trade businesses lose jobs because quotes go unanswered and leads wait too long for a reply. QuoteFlow is built to make follow-up automatic and safe.

**Core rule that runs through the whole app:** every business's data is isolated from every other business's data (multi-tenant isolation). A customer of one business can never see another business's records. This was tested and audited (section 10).

---

## 2. Foundation and platform

### 2.1 Laravel application and database
- **What we did:** scaffolded a Laravel 13 application with an Organization model (the business account), then ported the database layer from PostgreSQL to MySQL (commit `0e1e6fe`).
- **Why:** Laravel gives a tested, maintained base for authentication, queues, mail and scheduling. MySQL was chosen to match the hosting we use.
- **Purpose:** one codebase for the web app, background jobs and admin panel.

### 2.2 Interface system
- **What we did:** redesigned the app shell (sidebar layout, icons, summary cards), then applied one visual system (violet accent, green for success states) across all pages: dashboard, customers, estimates, reminders, automations, settings, billing, sign-in and sign-up. Several passes fixed spacing, mobile layouts and consistent buttons.
- **Why:** the first versions were inconsistent. Trade owners often use phones, so the layout had to work at phone width.
- **Purpose:** a clear, consistent interface that a non-technical owner can use without training.

### 2.3 Reliability foundations
- **What we did:** structured logging, error handling and monitoring (Task 16E); database indexing and performance work (Task 16D); background job, scheduler and automation hardening (Task 16B); email and webhook reliability (Task 16C).
- **Why:** messages and automations must not be lost, sent twice, or sent late without anyone knowing.
- **Purpose:** every background job is safe to run twice, late, or at the same time. Business effects are guarded by atomic database claims or unique keys. Details: `docs/queue-reliability.md`, `docs/database-performance.md`, `docs/logging-and-monitoring.md`, `docs/email-webhook-reliability.md`.

---

## 3. Accounts, sign-in and onboarding

### 3.1 Sign-up, email verification and sign-in
- **What we did:** a sign-up form creates the owner and business; a confirmation link is emailed. The confirmation link works in any browser, including one where nobody is signed in. Password reset was added. The sign-in pages were redesigned.
- **Why:** unverified email addresses cause spam accounts and undeliverable follow-ups. Some people open the link on a different device, so the link must not depend on a signed-in session.
- **Purpose:** confirmed owners only. The app warns in production if the mail driver is `log` (commit `b363451`), because in that case no confirmation email is actually sent. Mail settings are a server configuration item (section 11).

### 3.2 Google sign-in (branch, not merged)
- **What we did:** Google sign-in and sign-up through OAuth, with a business-setup step for new users. Google accounts must report a verified email. The code is on branch `feature/google-sign-in` (1 commit ahead of `main`, 9 passing tests).
- **Why:** many owners prefer one-click sign-in, and it reduces password resets.
- **Purpose:** faster sign-up. **Status:** not yet pushed to `main`. It needs Google Cloud OAuth credentials on the server (section 11).

### 3.3 Onboarding wizard
- **What we did:** a step-by-step setup (business details, timezone, country, email sender, WhatsApp, first customer). Steps have back navigation, a step counter and a checklist.
- **Why:** a new business must be able to start sending quotes in one sitting.
- **Purpose:** activation. See `docs/onboarding.md`.

### 3.4 Team, invitations and roles
- **What we did:** team invitations, role-based permissions (owner, manager, staff), and a settings area that hides pages a person cannot use. Staff cannot change business-wide settings or billing.
- **Why:** owners must control who can see revenue, change prices or send messages.
- **Purpose:** least-privilege access. See `docs/rbac.md` and `docs/team-and-settings.md`.

---

## 4. Business settings

- **Business profile and preferences:** name, logo, contact details, currency, country.
- **Timezone:** the business timezone follows the owner's choice and the business's location. Timezones are grouped by country, then popular zones, then all zones, each with its UTC offset (`app/Support/Timezones.php`).
  - **Why:** reminders, after-hours replies and "open now" checks must use the business's local time. Earlier, a Pakistani business could see USA times.
- **Opening hours (weekly):** owners set opening hours per day. These are the single source of truth for the AI assistant and for appointment times. A free-text hours field was removed because it conflicted with the structured hours.
- **Countries:** support was added for Pakistan, the Gulf, the UK, Europe, Asia and Africa (commit `2e55a62`).
  - **Purpose:** the business's country suggests its timezone and currency.

---

## 5. Customers, conversations and estimates

### 5.1 Customers and inbox
- **What we did:** customer list with summary cards and totals, add and edit forms, CSV import (`c7d77e8`), per-customer conversation workspace, and customer tags. Customers can be marked inactive after no reply and active again when they reply. Customers who opted out of email are never emailed.
- **Why:** one place for every customer's history and consent.
- **Purpose:** a single workspace per customer.

### 5.2 Estimates (quotes)
- **What we did:** estimate creation with line items, a catalog of items, draft and sent states, accept and decline, a list with date ranges and status pills, and a sticky action bar on desktop. Estimates can be imported from a CSV file, with column matching, row checks and a draft import (`docs/estimate-import.md`).
- **Why:** quotes are the core product. Owners need to send them quickly and see what happened.
- **Purpose:** send, track and close quotes. Quote outcomes (accepted, declined) notify the creator and the owner.

### 5.3 Estimate expiry
- **What we did:** a sent estimate expires after its valid-until date; expired estimates skip follow-ups scheduled for them. Accepted, declined and cancelled estimates never expire.
- **Why:** a customer should not be chased for a quote that is no longer valid.
- **Purpose:** correct follow-up behaviour.

---

## 6. Email sending and replies

### 6.1 Verified business email sending
- **What we did:** a business sends from its own domain (Postmark domain registration and verification), with DNS checks shown on the Email settings page.
- **Why:** mail from the business's own domain lands in inboxes more often and looks professional.
- **Purpose:** trusted sending. See `docs/email-domain-verification.md`, `docs/email-sending.md`.

### 6.2 Multiple sender options
- **What we did:** sender options: the platform's sending service, connected Google or Microsoft accounts, and custom SMTP. Each shows plain-language errors and warns the owner when an account needs reconnecting.
- **Why:** businesses already have Gmail or Outlook, and some use their own mail server.
- **Purpose:** flexibility without giving QuoteFlow the owner's password. See `docs/multi-provider-email.md`.

### 6.3 Replies and threading
- **What we did:** inbound email replies are matched to the right conversation, threaded, and their bounce notices are captured for SMTP, Gmail and Outlook. A reply is classified by AI for intent (ready to book, negotiating, needs attention). The AI only analyses; it never sends, changes quotes or books anything.
- **Why:** an owner must see replies, and bounces must stop future sends to bad addresses.
- **Purpose:** a complete conversation history. See `docs/email-inbound-replies.md`, `docs/ai-reply-classification.md`.

### 6.4 Follow-ups
- **What we did:** follow-up reminders and repeated follow-up emails up to a chosen number of attempts. Follow-ups stop on any real reply. The "New lead" follow-up template was added.
- **Why:** most jobs are won on the second or third reply, not the first.
- **Purpose:** timely follow-up with clear stop rules. See `docs/follow-ups.md`.

---

## 7. WhatsApp

### 7.1 Channels, connection and consent (phase 1)
- **What we did:** a WhatsApp connection record per business, with consent tracking. A customer must opt in before the business can message them on WhatsApp.
- **Why:** WhatsApp rules (Meta policy) require opt-in. Messaging without consent risks the number being banned.
- **Purpose:** compliant WhatsApp use.

### 7.2 Send and receive (phase 2)
- **What we did:** send and receive text, photos, voice notes and documents. Voice notes are transcribed. Inbound messages are verified with the app secret signature.
- **Why:** many trade customers write in voice notes and photos.
- **Purpose:** a real two-way channel in the same inbox as email. Downloading media is limited to what the business needs.

### 7.3 One-click connect (Meta Embedded Signup)
- **What we did:** "Continue with Facebook" connects a business's WhatsApp number through QuoteFlow's own Meta app, instead of asking the owner to create a Meta app and paste IDs and tokens. The code exchange, app subscription, number registration and number check run in order. A malformed Meta App ID turns one-click connect off and says why on the page. The manual method stays available under "Connect manually (advanced)".
- **Why:** creating a Meta app manually took non-technical owners too long and often failed.
- **Purpose:** about 2 minutes to connect instead of about 20. See `docs/whatsapp-one-click-connect.md`.

### 7.4 Sending limits and pacing (branch, not merged)
- **What we did:** on branch `feature/whatsapp-send-limits` (1 commit, 6 passing tests): a pacing limit per WhatsApp number, a daily cap on new customers based on Meta's messaging tier, detection of Meta throttle errors (codes 4, 80007, 130429, 131056 and HTTP 429) which defers a message instead of failing it, and an owner alert when the limit is reached. Follow-ups are rescheduled, not lost.
- **Why:** Meta limits how many new people a number can message per day. A busy hour could otherwise get the number restricted.
- **Purpose:** protect the business's WhatsApp number. **Status:** not merged to `main`.

---

## 8. Automations

- **What we did:** a rule builder: WHEN (trigger) → WAIT (optional) → IF (conditions) → THEN (actions). Templates include "New lead follow-up". Automations can send email and WhatsApp. Each run is logged; failed runs notify the owner. A redesigned automations page shows stat cards with trends and safety notices.
- **Why:** owners want repeatable follow-up without writing code, and need to see what ran.
- **Purpose:** automatic, auditable actions. Each run is idempotent (safe to retry). Details: `docs/automations.md`, `docs/automation-foundation.md`.

---

## 9. Leads, ads and the AI assistant

### 9.1 Lead stages and assignment
- **What we did:** lead stages (New, Qualified, and so on), assignment to a team member, and Won/Lost reminders when a lead goes quiet.
- **Why:** leads are lost when nobody owns them.
- **Purpose:** clear ownership and a pipeline view.

### 9.2 Facebook and Instagram Lead Ads
- **What we did:** a business connects the Facebook Page that runs its lead ads. Each form submission becomes a customer within seconds and can start automations. It uses the same Meta app as one-click WhatsApp.
- **Why:** lead ads are a major lead source, and manual copying loses leads.
- **Purpose:** instant lead capture. Duplicate form fills update the same customer. See `docs/meta-lead-ads.md`.

### 9.3 AI model chain and key management
- **What we did:** several AI providers (Gemini, Groq, OpenRouter, Ollama, OpenAI) are tried in a chain. If one fails or hits its limit, the next is used and the failing one rests for a cooldown period. A business can use several API keys per provider, rotated when one hits its limit. Keys are set by the platform administrator in the admin panel.
- **Why:** a single provider's outage or quota must not stop customer replies. Free-tier limits are common.
- **Purpose:** continuity and lower cost. Keys are stored by the platform, not in code.

### 9.4 AI WhatsApp assistant
- **What we did:**
  - **What the assistant knows:** the business's profile, services, opening hours and the customer's own quotes (phase A, `86d0900`, `86cfac8`).
  - **Replies and qualification:** it answers leads, qualifies them, and hands over to a person when needed (`ea2c04e`).
  - **Quotes, appointments and after-hours:** it can give quote details (never accept or change a quote), offer free one-hour appointment slots within opening hours, and say when the business is closed and when it opens. Appointments are held for a person to confirm (`ecfd278`).
  - **Languages:** English, Urdu and Arabic, replying in the customer's language.
  - **Hourly budget (merged, PR #101):** each business gets a plan-based number of AI replies per hour (Free 30, Starter 60, Pro 150). When the budget is used up, the customer is told a team member will reply and the chat is handed to a person. The owner is notified at 80% and at 100%.
- **Why:**
  - Most leads message outside business hours.
  - Trade owners cannot reply to every message while on a job.
  - AI costs money and providers limit requests, so an hourly budget stops a busy hour from running up costs or flooding the provider.
- **Purpose:** answer fast and honestly, and never pretend to be a person who has confirmed something. The AI's output is treated as untrusted and checked by code: it can only send a reply in this chat, mark a lead Qualified, or hand over to a person.
- **Limits:** the assistant cannot accept quotes, book without a person's confirmation, or message anyone other than the customer in the current chat.

### 9.5 AI chat log
- **What we did:** AI conversations are saved to a file. When the file reaches a size limit, it is emailed to the owner and emptied.
- **Why:** owners need a record of what the AI said to customers, and the database must not grow without limit.
- **Purpose:** review and audit of AI replies.

---

## 10. Billing, plans and security

### 10.1 Plans and billing
- **What we did:** a billing foundation with plans (Free, Starter, Pro) and feature limits (`config/billing.php`). Stripe checkout, a 14-day card-free trial, webhooks that sync payment state, trial and payment-failure reminders, a grace period, and a restricted state with a choose-plan path. Usage is tracked against limits.
- **Why:** the product must earn revenue, and limits must be enforced by code, not by trust.
- **Purpose:** clear plans and automatic enforcement. See `docs/billing.md`.
- **Status:** the code is complete. Live Stripe keys are a server configuration item (section 11).

### 10.2 Security audit and tenant isolation (Task 16A)
- **What we did:** a full audit of models, policies, gates, middleware, controllers, Livewire components, services, jobs and webhooks. Each finding was verified against the code and has a regression test in `tests/Feature/Security`. Mailbox connections were hardened after a separate email audit.
- **Why:** a multi-business product is only safe if no business can read another's data.
- **Purpose:** verified isolation. See `docs/security-audit.md`, `docs/security-review.md`.

### 10.3 Admin panel (Super Admin)
- **What we did:** a protected admin panel (Filament 4) at `/admin`, with two-factor authentication, role-based platform permissions (Spatie), organization management, user and access management, plan and feature-limit management, billing and usage views, a dashboard, a platform audit log and a tenant-isolation test matrix.
- **Why:** platform operators must manage businesses and limits without direct database access, and every change must be traceable.
- **Purpose:** operations. Every platform-admin action is logged (`PlatformAuditLog`). See `docs/admin-panel.md`, `docs/admin-audit.md`, `docs/admin-security.md`.

### 10.4 Suspension
- **What we did:** a suspended business's automations and sends are paused.
- **Why:** unpaid or abusive accounts must not keep sending.
- **Purpose:** platform control.

---

## 11. Settings the server owner must still provide

These are not code gaps. They are configuration values that only the platform owner can supply. Secrets must be entered in the server environment and never pasted into chat or committed.

| Item | Needed for | Status |
|---|---|---|
| `MAIL_*` (a real mail provider, not `log`) | Confirmation and notification emails | Not confirmed |
| `APP_URL` | Correct links in emails and redirects | Not confirmed |
| `GOOGLE_*` (OAuth client) | Google sign-in | Branch not merged |
| `META_*` (App ID, App Secret, Configuration ID, verify token) | One-click WhatsApp and Lead Ads | Meta app setup and business verification pending |
| Stripe keys | Live billing | Not confirmed |
| AI provider keys | AI assistant | Set in admin settings |
| Domain and website | Public site, legal pages | Not started |
| Terms and Privacy pages | Legal requirement for sign-up | Not started |

After changing environment values, run `php artisan optimize:clear`.

---

## 12. Branches and what is live

| Branch | Content | Status |
|---|---|---|
| `main` | All merged work through PR #100 | Live code base |
| `master` (PR #101, merged) | AI hourly reply budget | Merged into `main` |
| `ccr-29fb9c59-b9rrlw` | Designated branch; old history from PR #65 | Remote is stale; local copy differs (see section 13) |
| `feature/google-sign-in` | Google sign-in | 1 commit ahead of `main`, not merged |
| `feature/whatsapp-send-limits` | WhatsApp pacing, Meta limits, deferral | 1 commit ahead of `main`, not merged |

**Merge order note:** `feature/whatsapp-send-limits` changes the same assistant send path and notification enum as the merged hourly budget. Expect a small conflict in `WhatsAppAssistant.php`, `NotificationType.php` and `ActivityNotifications.php` when it is merged.

---

## 13. Open issues and known risks

1. **Designated branch is stale.** The remote `ccr-29fb9c59-b9rrlw` holds PR #65 history. The local copy has been rebuilt on `main`, so the stop hook reports unpushed commits. Resolving this needs an owner decision (force update or a new branch name).
2. **Local test database.** Seven estimate-expiry tests fail locally with a `SKIP LOCKED` syntax error from the local MariaDB setup. The error comes from the database engine, not from the test logic. This has not yet been confirmed on MySQL 8 or in CI, so it must be checked before launch.
3. **Marketing wording.** The sign-in page mentions SMS, which is not built. The wording should be corrected before launch.
4. **Legal pages.** Terms and Privacy pages are required before taking payments or collecting personal data.
5. **Pricing.** A founding plan at a fixed monthly price with capped spots was proposed but not built. It needs the owner's confirmation of price, spots and billing period.
6. **Mail configuration.** Confirmation emails do not arrive while the mail driver is `log`. The app now warns about this, but the server must be configured.
7. **Cost per business.** AI, WhatsApp and email costs per business are not yet measured in production. The hourly AI budget and WhatsApp pacing are the controls in place.

---

## 14. Decisions and why

| Decision | Reason |
|---|---|
| Multi-tenant isolation tested in code | A leak between businesses is the most serious failure a product like this can have. |
| AI output is untrusted; code decides what it can do | An AI can be wrong or manipulated. Only three actions are allowed, each checked. |
| Background jobs are idempotent | Retries and duplicates are normal in queues; double-sending a quote is not acceptable. |
| Hourly AI budget with human handover | Protects cost and provider limits, and keeps a person in the loop when the AI is unavailable or busy. |
| WhatsApp consent required before messaging | Meta policy, and protection of the business's number. |
| Plan limits enforced in code | Limits set in the interface alone can be bypassed. |
| One Meta app for all businesses (Embedded Signup) | Removes the setup burden for owners. The platform owner carries the Meta app review. |
| Passwords and tokens never in code or chat | Prevents leaks through version control or logs. |

---

## 15. Review checklist

- [ ] `main` builds and its test suite runs (7 local MariaDB failures noted in section 13).
- [ ] Mail driver is not `log` on the server; a test confirmation email arrives.
- [ ] Stripe, Google and Meta keys are set in the server environment, not in code.
- [ ] `php artisan optimize:clear` has been run after the last configuration change.
- [ ] Terms and Privacy pages are published.
- [ ] Remaining branches (`feature/google-sign-in`, `feature/whatsapp-send-limits`) are reviewed and merged or closed.
- [ ] The designated branch question (section 13, item 1) is decided.

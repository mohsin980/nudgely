# QuoteFlow: State Machines (Reference)

**What this is:** a plain list of every lifecycle in the app that moves through stages, such as a quote going from draft to accepted. For each one it says what the stages are, where the code changes them, and how sure we are of the allowed moves.

**Why it exists:** many of these lifecycles have their rules spread across several services. This page lets a developer see the full picture in one place, and it gives the target for turning each lifecycle into a single written list of allowed moves.

---

## 1. Words used here

- **State:** one stage something can be in. Example: a quote is `draft`.
- **Transition:** a move from one state to another. Example: `draft` → `sent`.
- **Allowed moves:** the transitions the business rules permit. Anything else must be refused.
- **Explicit:** the allowed moves are written in code in one place (a method that answers "can this move happen?").
- **Implicit:** the states are in code, but the allowed moves are only checked in scattered `if` statements.
- **Conditional update:** a database write that only succeeds if the row is still in the expected state. It stops two requests from both making the same move.

## 2. Status at a glance

| # | Lifecycle | Stages | Rules in code | Where the stage changes |
|---|---|---|---|---|
| 1 | Message delivery | queued, sending, sent, delivered, bounced, complained, failed, received, needs_review | **Explicit** | `MessageStatus`, `WhatsAppMessenger`, `DeliveryEventProcessor` |
| 2 | Follow-up | pending, due, completed, cancelled, skipped, failed | **Explicit** | `FollowUpStatus`, `FollowUpProcessor`, `FollowUpService` |
| 3 | Automation (the rule) | draft, active, paused, archived | Partly explicit (`canActivate()` only) | `AutomationStatus`, `AutomationBuilder` |
| 4 | Estimate (quote) | draft, sent, viewed, accepted, declined, expired, cancelled | Implicit | `EstimateService`, `EstimateStatus` |
| 5 | Subscription (billing) | trialing, active, past_due, paused, cancelled, incomplete, unpaid, expired | Implicit | `BillingLifecycle`, `BillingWebhookHandler`, `StripeWebhookController` |
| 6 | Automation run | waiting, running, completed, failed, skipped | Implicit | `AutomationEngine` |
| 7 | Automation action run | pending, running, completed, failed, skipped | Implicit | `AutomationEngine` |
| 8 | Conversation | open, waiting_customer, waiting_business, closed | Implicit | `ConversationService`, `Conversation` |
| 9 | Lead stage | new, contacted, qualified, quoted, won, lost | Implicit (ordered by rank) | `LeadStageService` |
| 10 | Customer | active, inactive | Implicit | `CustomerService`, `CustomerActivityService` |
| 11 | AI assistant chat | active, handed over | Implicit | `AssistantHandover`, `WhatsAppAssistant` |
| 12 | Estimate import | uploaded, ready, queued, processing, completed, failed | Implicit | `EstimateImportService` |
| 13 | Lead Ads lead | pending, imported, matched, failed | Implicit | `LeadAdsService` |
| 14 | Lead Ads page | active, failed | Implicit | `LeadAdsService` |
| 15 | WhatsApp connection | pending, active, failed | Implicit | `WhatsAppConnectionService` |
| 16 | Email connection | active, reauth_required, disconnected | Implicit | `EmailConnection`, email OAuth services |
| 17 | Team member | invited, active, suspended, removed | Implicit | `TeamService` |
| 18 | Team invitation | open, expired, used | Implicit | `InvitationService`, `TeamInvitation` |
| 19 | Appointment request | requested, confirmed, declined | Implicit | `AppointmentBooking` |
| 20 | Webhook event (inbound) | received, processing, processed, failed, ignored | Partly explicit (`isFinished()`) | `WebhookEvent`, webhook processors |
| 21 | Billing webhook event | received, processing, processed, failed, ignored | Implicit (atomic claim) | `BillingWebhookHandler` |
| 22 | Task | pending, completed, cancelled | Implicit | `TaskService` |
| 23 | Organization | active, suspended | Implicit (boolean-like) | `Organization::isSuspended()` |
| 24 | Onboarding | ordered steps | Implicit (ordered) | `OnboardingService`, `OnboardingState` |

---

## 3. Explicit lifecycles (the model to copy)

### 3.1 Message delivery
**Stages:** queued, sending, sent, delivered, bounced, complained, failed, received, needs_review.

**Allowed moves** (from `MessageStatus::canTransitionTo`):
- queued → sending, failed
- sending → sent, queued, failed
- sent → delivered, bounced, complained
- delivered → complained
- failed → delivered, bounced, complained, **only if the move comes from a provider event** (a send that timed out is recorded as failed, and the provider can later confirm it)
- Nothing else is allowed. A late or repeated event cannot move a message backwards.

### 3.2 Follow-up
**Stages:** pending, due, completed, cancelled, skipped, failed.

**Allowed moves** (from `FollowUpStatus::allowedTransitions`):
- pending → due, cancelled, skipped
- due → completed, cancelled, skipped, failed
- completed, cancelled, skipped and failed have no moves out.

### 3.3 Automation (the rule)
**Stages:** draft, active, paused, archived.

**Rules in code:** `AutomationStatus::canActivate()` decides whether a rule may be turned on. The other moves (active ↔ paused, anything → archived) are not yet written as a list.

---

## 4. Implicit lifecycles

For each one: the stages, the moves we can see in the code, and what still needs to be decided. "Seen in code" means a method or test does that move. "Inferred" means it follows from the stage names and needs checking.

### 4.1 Estimate (quote)
**Stages:** draft, sent, viewed, accepted, declined, expired, cancelled.

**Moves seen in code:**
- draft → sent: `EstimateService::send` (checks: has items, total above zero, not past "valid until", customer has a valid, active, opted-in email).
- sent or viewed → accepted or declined: `EstimateService::decide` (the customer's decision on the public page).
- sent or viewed → expired: the expiry job, once "valid until" has passed. Accepted, declined and cancelled quotes never expire.
- Cancelled: `cancelLocked`, also used when a newer version replaces it.
- Revision: `canBeRevised()` decides whether a quote can be edited into a new version.

**Guards in code:** `isDraft()`, `isEditable()`, `canBeRevised()`, row locks (`lockForUpdate`) inside a transaction.

**Still to decide:** the full list of allowed moves, including whether a viewed quote can go back to sent, and whether an accepted quote can ever be cancelled.

### 4.2 Subscription (billing)
**Stages:** trialing, active, past_due, paused, cancelled, incomplete, unpaid, expired.

**Moves seen in code and tests:**
- trialing → active when the trial converts after payment.
- active → past_due when a payment fails. A grace period starts.
- past_due → active when the payment is recovered.
- past_due → restricted (paid features off) when the grace period ends. This is recorded as a restriction, not a separate stage.
- active → cancelled at the end of the paid period, after cancellation was requested.
- trialing → expired when a trial ends without a plan (`expireTrials`).

**Guards in code:** several updates only run `where('status', ...)`, so a second request cannot repeat the same move.

**Still to decide:** the full allowed-moves list, and what paused and unpaid mean in the app.

### 4.3 Automation run
**Stages:** waiting, running, completed, failed, skipped.

**Moves (inferred from stage names; check in `AutomationEngine`):** waiting → running (when the wait ends), running → completed, running → failed, waiting or running → skipped (when conditions no longer match).

**Still to decide:** the full list, and whether a failed run can be retried.

### 4.4 Automation action run
**Stages:** pending, running, completed, failed, skipped.

**Moves (inferred):** pending → running → completed or failed; pending → skipped. Each action runs once per run because of a unique key.

**Still to decide:** whether a failed action is retried and how many times.

### 4.5 Conversation
**Stages:** open, waiting_customer, waiting_business, closed.

**Moves (inferred from stage names; check `ConversationService`):** open → waiting_customer when the team sends a message; open or waiting_customer → waiting_business when the customer replies; any → closed; closed → open when the customer writes again.

**Still to decide:** the full list, and what closing means for follow-ups.

### 4.6 Lead stage
**Stages in order:** new, contacted, qualified, quoted, won, lost.

**Moves seen in code:** `LeadStageService::advance` only moves a lead forward in rank, and does nothing once the lead is won or lost. A declined quote makes the lead lost, unless another quote is still waiting.

**Still to decide:** whether a lost lead can be reopened, and what a "won" lead can still do.

### 4.7 Customer
**Stages:** active, inactive.

**Moves seen in code:** a customer becomes inactive after no reply for the set period. A reply from an inactive customer makes them active again. Staff can change it by hand.

### 4.8 AI assistant chat
**Stages:** active, handed over.

**Moves seen in code:** active → handed over when a person must take over (a time to confirm, the reply limit, the AI being unavailable, the hourly budget running out, or a team member replying). A handed-over chat is not answered by the AI again.

**Still to decide:** whether a handed-over chat can ever become active again. A new day starts a new chat.

### 4.9 Estimate import
**Stages:** uploaded, ready, queued, processing, completed, failed.

**Moves (inferred from stage names; check `EstimateImportService`):** uploaded → ready (after the file is checked) → queued → processing → completed or failed.

**Still to decide:** whether a failed import can be restarted, and whether a completed one can be re-run.

### 4.10 Lead Ads lead and page
**Lead stages:** pending, imported, matched, failed. **Page stages:** active, failed.

**Moves (inferred):** a lead goes pending → imported (new customer) or matched (existing customer), or pending → failed. A page is active until its connection fails.

### 4.11 WhatsApp connection
**Stages:** pending, active, failed.

**Moves (inferred):** pending → active when the code exchange, subscription and number check all succeed; pending → failed when any step fails. Nothing is saved on failure.

### 4.12 Email connection
**Stages:** active, reauth_required, disconnected.

**Moves (inferred):** active → reauth_required when the provider refuses access; reauth_required → active after reconnecting; any → disconnected when the owner removes it.

### 4.13 Team member and invitation
**Member stages:** invited, active, suspended, removed. **Invitation stages:** open, expired, used.

**Moves (inferred):** invited → active when the invitation is accepted; open → used on acceptance; open → expired after its time limit; active ↔ suspended; any → removed.

### 4.14 Appointment request
**Stages:** requested, confirmed, declined.

**Moves (inferred):** requested → confirmed or declined by a team member. Only staff with permission can confirm. A request with a time that is no longer free must be offered another time.

### 4.15 Webhook events (inbound, and billing)
**Stages:** received, processing, processed, failed, ignored.

**Moves (inferred):** received → processing (claimed atomically, so only one worker takes it) → processed, failed or ignored. `isFinished()` marks the end states. A failed event can be retried.

### 4.16 Task
**Stages:** pending, completed, cancelled.

**Moves (inferred):** pending → completed or cancelled. Assignment does not change the stage.

### 4.17 Organization
**Stages:** active, suspended.

**Moves (inferred):** active ↔ suspended by a platform administrator. A suspended business's sends and automations are paused.

### 4.18 Onboarding
**Stages:** an ordered list: business profile, preferences, follow-up channels, email connection, WhatsApp connection, first customer, first estimate, first automation, completed.

**Moves:** steps can be done in any order and skipped; the checklist shows each step's status.

---

## 5. How to add a rule (the target pattern)

Every lifecycle should follow the message-delivery pattern:

1. **Write the states** as an enum, with one value for each stage.
2. **Write the allowed moves** in one method, for example `canTransitionTo(next)`. Keep it as a plain list, so it can be read without running code.
3. **Check the move before writing it**, and make the database write conditional (update only if the row is still in the expected state).
4. **Add a test** for each allowed move, and one for each forbidden move that matters.
5. **Update this page** when a move is added or removed.

## 6. Priority for turning implicit lifecycles into explicit ones

Ordered by business risk:

1. **Estimate** (customers see and accept it).
2. **Subscription** (money).
3. **Automation run and action run** (messages go out automatically).
4. **Conversation** and **AI assistant chat** (what customers are told).
5. **Estimate import** (data changes in bulk).
6. **Team member and invitation** (who can access the business).
7. Everything else.

## 7. Open questions for the team

For each lifecycle above, the "still to decide" lines need an answer before its rules can be written. The most important are:
- Can a sent or viewed quote go back to draft or be cancelled?
- Can a lost lead or a cancelled subscription be reopened, and how?
- Can a failed automation run be retried?
- Can a handed-over AI chat go back to the AI?

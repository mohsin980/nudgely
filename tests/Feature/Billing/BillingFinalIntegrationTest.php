<?php

use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Jobs\Billing\CheckGracePeriodsJob;
use App\Jobs\Billing\ExpireTrialsJob;
use App\Jobs\Billing\NotifyOwnerJob;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\BillingPlans;
use App\Livewire\Settings\BillingUsage;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingBanner;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

/*
 * Task 14F: the whole billing lifecycle end to end. Each scenario checks that billing state, subscription
 * state, entitlements, usage, UI and notifications agree, and that no business data is ever removed.
 */

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness();
    $this->organization->forceFill(['trial_ends_at' => null])->save();
    $this->stripe = FakeStripe::install();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    config(['billing.enforce_limits' => true]);
});

/** The owner pays for a plan on Stripe; returns the local subscription (trialing, as a first Stripe subscription is). */
function subscribed(User $owner, string $plan = 'starter'): Subscription
{
    $session = app(BillingService::class)->startCheckout($owner, $plan, 'https://app.test/ok', 'https://app.test/cancel');
    test()->stripe->completeCheckout($session->id);

    return app(BillingService::class)->completeCheckout($owner->fresh(), $session->id);
}

/** A subscription whose trial is over and which is paying normally. */
function paying(User $owner, string $plan = 'starter'): Subscription
{
    $subscription = subscribed($owner, $plan);
    test()->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);

    return app(BillingService::class)->refresh($owner->organization->fresh());
}

function stripeSays(Subscription $subscription, array $changes): void
{
    foreach ($changes as $key => $value) {
        test()->stripe->subscriptions[$subscription->provider_subscription_id][$key] = $value;
    }
}

function tellApp(string $type, Subscription $subscription, ?string $id = null): void
{
    $customer = Organization::find($subscription->organization_id)->billing_customer_id;
    $object = str_starts_with($type, 'invoice.') ? ['customer' => $customer, 'subscription' => $subscription->provider_subscription_id] : ['id' => $subscription->provider_subscription_id, 'customer' => $customer];
    webhook(stripeEvent($type, $object, $id))->assertOk();
}

function audit(Organization $organization, string $action)
{
    return OrganizationActivity::where('organization_id', $organization->id)->where('action', $action);
}

function ownerNotices(User $owner, ?string $kind = null)
{
    return $owner->fresh()->notifications()->get()->filter(fn ($n) => $kind === null || $n->data['kind'] === $kind)->values();
}

function banner(Organization $organization): ?string
{
    return app(BillingBanner::class)->for($organization->fresh())['kind'] ?? null;
}

/** Everything that must agree about a subscription's plan and status. */
function expectConsistent(User $owner, string $planKey, ?SubscriptionStatus $status): void
{
    $organization = $owner->organization->fresh();
    $entitlements = app(EntitlementService::class);
    $plan = app(PlanCatalog::class)->get($planKey);
    $current = app(BillingService::class)->currentSubscription($organization);

    expect($entitlements->plan($organization)->key)->toBe($planKey)
        ->and($entitlements->limit($organization, LimitKey::Customers))->toBe($plan->limit(LimitKey::Customers))
        ->and($current?->status)->toBe($status);

    Livewire::actingAs($owner->fresh())->test(BillingOverview::class)->assertSee($plan->name)->when($status !== null, fn ($page) => $page->assertSee($status->label()));
    Livewire::actingAs($owner->fresh())->test(BillingUsage::class)->assertSee('/ '.number_format($plan->limit(LimitKey::Customers)), false);
}

// ─── 1. Trial → Active ───────────────────────────────────────────────────────────────────

test('1. trial → active: the paid trial converts, the owner is told, and everything agrees', function () {
    $subscription = subscribed($this->owner);
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Trialing);

    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id); // the trial ends and Stripe charges
    tellApp('customer.subscription.updated', $subscription);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(audit($this->organization, 'trial_converted')->count())->toBe(1)
        ->and(ownerNotices($this->owner, 'subscription_renewed')->first()->data['message'])->toContain('Your trial has ended and your Starter subscription is now active.')
        ->and(banner($this->organization))->toBeNull();
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Active);

    tellApp('customer.subscription.updated', $subscription); // Stripe repeats itself: nothing more happens
    expect(audit($this->organization, 'trial_converted')->count())->toBe(1)->and(ownerNotices($this->owner, 'subscription_renewed'))->toHaveCount(1);
});

// ─── 2. Trial → Expired ──────────────────────────────────────────────────────────────────

test('2. trial → expired: nothing is deleted, the owner is told, Free limits apply, and it happens once', function () {
    app(BillingService::class)->startSignupTrial($this->organization);
    Customer::factory()->count(120)->for($this->organization)->create(); // more than Free allows
    expect(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')->and(banner($this->organization))->toBeNull();

    $this->travel(10)->days(); // 4 days left
    expect(banner($this->organization))->toBe('trial_ending');

    $this->travel(5)->days(); // over
    $this->artisan('billing:expire-trials')->expectsOutputToContain('1 trial(s) expired.')->assertSuccessful();

    $organization = $this->organization->fresh();
    $notice = ownerNotices($this->owner, 'trial_ended')->sole();
    expect($organization->trial_expired_at)->not->toBeNull()
        ->and(audit($organization, 'trial_expired')->count())->toBe(1)
        ->and(audit($organization, 'billing_restriction_applied')->sole()->data)->toMatchArray(['reason' => 'trial_expired', 'plan' => 'free'])
        ->and(audit($organization, 'billing_restriction_applied')->sole()->data['over_limits'])->toContain('customers: 121/100')
        ->and($notice->data['message'])->toContain('Your QuoteFollow trial has ended.')->and($notice->data['url'])->toBe(route('settings.billing.plans'))->and($notice->data['action'])->toBe('Choose a Plan')
        ->and(ownerNotices($this->manager))->toHaveCount(0)
        ->and(app(EntitlementService::class)->plan($organization)->key)->toBe('free')
        ->and(Customer::where('organization_id', $organization->id)->count())->toBe(121) // nothing deleted
        ->and(banner($organization))->toBe('expired');

    // Idempotent: the job and the command can run again and again.
    $this->artisan('billing:expire-trials')->expectsOutputToContain('0 trial(s) expired.');
    (new ExpireTrialsJob)->handle(app(BillingLifecycle::class));
    expect(audit($organization, 'trial_expired')->count())->toBe(1)->and(ownerNotices($this->owner, 'trial_ended'))->toHaveCount(1);
});

test('a trial that converted to a subscription is never expired', function () {
    app(BillingService::class)->startSignupTrial($this->organization);
    subscribed($this->owner, 'pro');
    $this->travel(15)->days();

    expect(app(BillingLifecycle::class)->expireTrials())->toBe(0)->and(audit($this->organization, 'trial_expired')->count())->toBe(0);
});

// ─── 3. Active → Payment Failed ──────────────────────────────────────────────────────────

test('3. active → payment failed: grace starts, the owner is told once, access continues', function () {
    $subscription = paying($this->owner);
    Queue::fake();
    stripeSays($subscription, ['status' => 'past_due']);

    tellApp('invoice.payment_failed', $subscription, 'evt_pf_1');
    tellApp('invoice.payment_failed', $subscription, 'evt_pf_2'); // Stripe's retry sends another event
    tellApp('customer.subscription.updated', $subscription);

    $subscription = $subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)->and($subscription->past_due_since)->not->toBeNull()
        ->and($subscription->inGracePeriod())->toBeTrue()
        ->and(audit($this->organization, 'payment_failed')->count())->toBe(1)
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter') // grace: normal access
        ->and(banner($this->organization))->toBe('past_due');
    Queue::assertPushed(NotifyOwnerJob::class, 1);
});

test('3b. the payment failure notice says what to do and links to the payment method page', function () {
    $subscription = paying($this->owner);
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);

    $notice = ownerNotices($this->owner, 'payment_failed')->sole();
    $graceEnd = $this->organization->formatDate($subscription->fresh()->graceEndsAt());
    expect($notice->data['message'])->toBe("We couldn't process your payment. Update your payment method by {$graceEnd} to keep your Starter plan.")
        ->and($notice->data['action'])->toBe('Update Payment Method')->and($notice->data['url'])->toBe(route('settings.billing.payment-method'))
        ->and(ownerNotices($this->manager))->toHaveCount(0);

    $this->actingAs($this->owner->fresh())->get(route('settings.billing.payment-method'))->assertRedirect('https://billing.stripe.test/p/session_1');
    $this->actingAs($this->manager->fresh())->get(route('settings.billing.payment-method'))->assertForbidden();
});

// ─── 4. Payment Failed → Recovered ───────────────────────────────────────────────────────

test('4. payment failed → recovered: active again, the owner is told, the grace clock resets', function () {
    $subscription = paying($this->owner);
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);

    stripeSays($subscription, ['status' => 'active']);
    tellApp('invoice.paid', $subscription, 'evt_paid_after_failure');

    $subscription = $subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->past_due_since)->toBeNull()->and($subscription->restricted_at)->toBeNull()
        ->and(audit($this->organization, 'payment_recovered')->count())->toBe(1)
        ->and(ownerNotices($this->owner, 'payment_recovered')->sole()->data['message'])->toBe('Your payment was successful and your account is active again.')
        ->and(banner($this->organization))->toBeNull();
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Active);

    // A second failure later is a new episode with its own notification.
    $this->travel(2)->hours();
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);
    expect(ownerNotices($this->owner, 'payment_failed'))->toHaveCount(2);
});

// ─── 5. Active → Cancellation Scheduled, 6. Resumed ──────────────────────────────────────

test('5. active → cancellation scheduled: the date is shown, the plan stays, the owner is told', function () {
    $subscription = paying($this->owner);
    $until = $this->organization->formatDate($subscription->current_period_end);

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('cancelSubscription')->assertSee('remains active until')->assertSee($until)->assertSee('Resume subscription');

    $subscription = $subscription->fresh();
    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and(audit($this->organization, 'subscription_cancelled')->sole()->user_id)->toBe($this->owner->id)
        ->and(ownerNotices($this->owner, 'subscription_cancelling')->sole()->data['message'])->toContain("until {$until}")
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')
        ->and(banner($this->organization))->toBe('cancel_scheduled');

    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertSee('Your subscription ends on '.$until)->assertSee('Resume Subscription');
    expect(audit($this->organization, 'subscription_ended')->count())->toBe(0);
});

test('5b. a cancellation made in the Stripe portal is picked up from the webhook', function () {
    $subscription = paying($this->owner);
    stripeSays($subscription, ['cancel_at_period_end' => true]);

    tellApp('customer.subscription.updated', $subscription);
    tellApp('customer.subscription.updated', $subscription);

    expect(audit($this->organization, 'subscription_cancelled')->sole()->data)->toMatchArray(['via' => 'provider', 'at_period_end' => true])
        ->and(ownerNotices($this->owner, 'subscription_cancelling'))->toHaveCount(1)->and(banner($this->organization))->toBe('cancel_scheduled');
});

test('6. cancellation → resumed: the plan continues and the banner goes away', function () {
    $subscription = paying($this->owner);
    $page = Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('cancelSubscription');

    $page->call('resumeSubscription')->assertSee('Your subscription will continue.')->assertDontSee('Cancellation is scheduled');

    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse()->and(audit($this->organization, 'subscription_resumed')->count())->toBe(1)
        ->and(banner($this->organization))->toBeNull();
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Active);
});

// ─── 7. Active → Cancelled ───────────────────────────────────────────────────────────────

test('7. active → cancelled: at the period end the plan ends, the owner is told, data stays', function () {
    $subscription = paying($this->owner);
    Customer::factory()->count(150)->for($this->organization)->create();
    app(BillingService::class)->cancel($this->owner->fresh());

    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    tellApp('customer.subscription.deleted', $subscription);

    $subscription = $subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(audit($this->organization, 'subscription_ended')->count())->toBe(1)
        ->and(ownerNotices($this->owner, 'subscription_ended')->sole()->data['message'])->toContain('Your Starter subscription has ended')
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free')
        ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(151)
        ->and(banner($this->organization))->toBe('expired');
    expectConsistent($this->owner, 'free', null);

    tellApp('customer.subscription.deleted', $subscription); // repeated
    expect(ownerNotices($this->owner, 'subscription_ended'))->toHaveCount(1);
});

// ─── 8 / 9. Plan upgrade and downgrade ───────────────────────────────────────────────────

test('8. plan upgrade: limits rise at once and the change is audited', function () {
    $subscription = paying($this->owner);

    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->call('choosePlan', 'pro');

    expect($subscription->fresh()->plan)->toBe('pro')
        ->and(audit($this->organization, 'subscription_plan_changed')->count())->toBe(1)
        ->and(app(EntitlementService::class)->limit($this->organization, LimitKey::Customers))->toBe(2000);
    expectConsistent($this->owner, 'pro', SubscriptionStatus::Active);

    // The same change made in the Stripe portal reaches us through the webhook, also audited.
    stripeSays($subscription, ['items' => ['data' => [['price' => ['id' => 'price_starter'], 'current_period_start' => time(), 'current_period_end' => time() + 86400 * 30]]]]);
    $this->stripe->subscriptions[$subscription->provider_subscription_id]['items']['data'][0] = array_merge($this->stripe->subscriptions[$subscription->provider_subscription_id]['items']['data'][0], ['price' => ['id' => 'price_starter']]);
    tellApp('customer.subscription.updated', $subscription);
    expect($subscription->fresh()->plan)->toBe('starter')->and(audit($this->organization, 'subscription_plan_changed')->count())->toBe(2);
});

test('9. plan downgrade: scheduled for the period end, data kept, then applied', function () {
    $subscription = paying($this->owner, 'pro');
    Customer::factory()->count(600)->for($this->organization)->create(); // more than Starter allows

    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->call('choosePlan', 'starter')->assertSee("You're on Pro until");

    expect($subscription->fresh()->scheduled_plan)->toBe('starter')->and(audit($this->organization, 'subscription_downgrade_scheduled')->count())->toBe(1);
    expectConsistent($this->owner, 'pro', SubscriptionStatus::Active); // still Pro until the period ends

    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    tellApp('customer.subscription.updated', $subscription);

    expect($subscription->fresh()->plan)->toBe('starter')->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(601)
        ->and(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeFalse() // over the new limit: only adding is refused
        ->and(audit($this->organization, 'subscription_plan_changed')->count())->toBe(1);
    expect(ownerNotices($this->owner, 'subscription_renewed')->pluck('data.message')->implode('|'))->toContain('Starter plan renewed');
});

// ─── 10. Usage limit ─────────────────────────────────────────────────────────────────────

test('10. usage limit: blocked with an upgrade path, then unlocked by paying', function () {
    tightFreePlan(['customers' => 2]);
    $add = fn (string $email) => Livewire::actingAs($this->owner->fresh())->test(CustomerForm::class)->set('first_name', 'A')->set('last_name', 'B')->set('email', $email)->call('save');

    $add('one@example.com')->assertHasNoErrors();
    $add('two@example.com')->assertHasErrors('email')->assertSee('Upgrade Plan');
    Livewire::actingAs($this->owner->fresh())->test(BillingUsage::class)->assertSee('2 / 2');

    $subscription = subscribed($this->owner);
    tellApp('customer.subscription.created', $subscription);

    $add('two@example.com')->assertHasNoErrors();
    expect(Customer::where('organization_id', $this->organization->id)->count())->toBe(3);
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Trialing);
});

// ─── 11. Billing restriction (grace period) ──────────────────────────────────────────────

test('11. billing restriction: normal access through the grace period, then Free limits, never deletion', function () {
    $subscription = paying($this->owner);
    Customer::factory()->count(200)->for($this->organization)->create(); // fine on Starter (500), over Free (100)
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);
    $graceDays = config('billing.grace_days');
    expect($graceDays)->toBe(7);

    // During grace the job does nothing.
    $this->travel($graceDays - 1)->days();
    $this->artisan('billing:check-grace-periods')->expectsOutputToContain('0 restricted')->assertSuccessful();
    expect(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')->and(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeTrue()
        ->and(banner($this->organization))->toBe('past_due');

    // After grace, with the payment still failing: restricted, once.
    $this->travel(2)->days();
    $this->artisan('billing:check-grace-periods')->expectsOutputToContain('1 restricted');
    (new CheckGracePeriodsJob)->handle(app(BillingLifecycle::class));
    $this->artisan('billing:check-grace-periods')->expectsOutputToContain('0 restricted');

    $subscription = $subscription->fresh();
    $restriction = audit($this->organization, 'billing_restriction_applied')->sole();
    expect($subscription->restricted_at)->not->toBeNull()->and($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($restriction->data)->toMatchArray(['reason' => 'grace_expired', 'plan' => 'free'])
        ->and(ownerNotices($this->owner, 'billing_restricted'))->toHaveCount(1)
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free')
        ->and(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeFalse()
        ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(201) // nothing deleted
        ->and(banner($this->organization))->toBe('restricted');

    Livewire::actingAs($this->owner->fresh())->test(CustomerForm::class)->set('first_name', 'A')->set('last_name', 'B')->set('email', 'new@example.com')->call('save')->assertHasErrors('email')->assertSee('Upgrade Plan');
    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertSee('paused')->assertSee('Update Payment Method');

    // Paying restores everything.
    stripeSays($subscription, ['status' => 'active']);
    tellApp('invoice.paid', $subscription);

    expect(audit($this->organization, 'billing_restriction_lifted')->count())->toBe(1)->and(audit($this->organization, 'payment_recovered')->count())->toBe(1)
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')->and(banner($this->organization))->toBeNull();
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Active);
});

test('the grace check notices a payment that was fixed without a webhook', function () {
    $subscription = paying($this->owner);
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);
    stripeSays($subscription, ['status' => 'active']); // fixed at Stripe; the webhook never arrived

    $this->travel(8)->days();
    $result = app(BillingLifecycle::class)->checkGracePeriods();

    expect($result)->toMatchArray(['restricted' => 0, 'recovered' => 1])->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(audit($this->organization, 'billing_restriction_applied')->count())->toBe(0)->and(ownerNotices($this->owner, 'payment_recovered'))->toHaveCount(1);
});

test('the grace period is configurable', function () {
    config(['billing.grace_days' => 2]);
    $subscription = paying($this->owner);
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);

    $this->travel(3)->days();

    expect(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free')->and(app(BillingLifecycle::class)->checkGracePeriods()['restricted'])->toBe(1);
});

// ─── Banner ──────────────────────────────────────────────────────────────────────────────

test('healthy subscriptions and ordinary pages show no billing banner; only owners see banners', function () {
    paying($this->owner);

    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('data-billing-banner', false);

    $subscription = Subscription::sole();
    stripeSays($subscription, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $subscription);

    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertSee('data-billing-banner="past_due"', false);
    $this->actingAs($this->manager->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('data-billing-banner', false);
    $this->actingAs($this->owner->fresh())->get(route('settings.billing'))->assertDontSee('data-billing-banner', false); // the billing pages explain it themselves
});

test('the banner costs no provider calls', function () {
    paying($this->owner);
    $requests = count($this->stripe->requests);

    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk();

    expect(count($this->stripe->requests))->toBe($requests);
});

test('a paid trial that ends soon shows the trial banner', function () {
    $subscription = subscribed($this->owner);
    expect(banner($this->organization))->toBeNull(); // 14 days out

    $this->travel(9)->days();

    expect(banner($this->organization))->toBe('trial_ending');
    expect(app(BillingBanner::class)->for($this->organization->fresh())['message'])->toContain('Your Starter trial ends on');
});

// ─── Scheduler, jobs, queues ─────────────────────────────────────────────────────────────

test('the billing jobs are scheduled', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description ?: $e->command)->implode('|');

    expect($events)->toContain('billing-expire-trials')->toContain('billing-check-grace-periods')->toContain('billing:sync-subscriptions')->toContain('billing:send-trial-reminders');
});

test('owner notifications are queued: one job per episode, however often Stripe repeats itself', function () {
    $subscription = paying($this->owner);
    stripeSays($subscription, ['status' => 'past_due']);
    Queue::fake();

    tellApp('invoice.payment_failed', $subscription, 'evt_q_1');
    tellApp('invoice.payment_failed', $subscription, 'evt_q_2');

    Queue::assertPushed(NotifyOwnerJob::class, 1);
    Queue::assertPushed(NotifyOwnerJob::class, fn (NotifyOwnerJob $job) => $job->type === 'payment_failed' && $job->organizationId === $this->organization->id && $job->action === 'Update Payment Method');
});

test('running a notification job twice notifies once', function () {
    $job = new NotifyOwnerJob($this->organization->id, 'payment_failed', 'We could not process your payment.', route('settings.billing'), 'dup-key', 'Update Payment Method');

    $job->handle(app(TeamDirectory::class), app(TeamNotifier::class));
    $job->handle(app(TeamDirectory::class), app(TeamNotifier::class));

    expect(ownerNotices($this->owner, 'payment_failed'))->toHaveCount(1);
});

// ─── 12. Organization isolation ──────────────────────────────────────────────────────────

test('12. one business\'s billing problems never touch another business', function () {
    $mine = paying($this->owner);
    ['owner' => $otherOwner, 'manager' => $otherManager, 'organization' => $other] = teamBusiness('Other Co');
    $other->forceFill(['trial_ends_at' => null])->save();
    $theirs = paying($otherOwner, 'pro');

    $theirNotices = ownerNotices($otherOwner)->count();
    stripeSays($mine, ['status' => 'past_due']);
    tellApp('invoice.payment_failed', $mine);
    $this->travel(8)->days();
    $result = app(BillingLifecycle::class)->checkGracePeriods();

    expect($result['restricted'])->toBe(1)
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free')
        ->and(app(EntitlementService::class)->plan($other)->key)->toBe('pro')
        ->and($theirs->fresh()->status)->toBe(SubscriptionStatus::Active)->and($theirs->fresh()->past_due_since)->toBeNull()
        ->and(ownerNotices($otherOwner))->toHaveCount($theirNotices)->and(ownerNotices($otherManager))->toHaveCount(0)
        ->and(OrganizationActivity::where('organization_id', $other->id)->where('action', 'like', 'payment%')->count())->toBe(0)
        ->and(audit($other, 'billing_restriction_applied')->count())->toBe(0)
        ->and(banner($other))->toBeNull()->and(banner($this->organization))->toBe('restricted');

    // Trial expiry is per business as well.
    $a = teamBusiness('Trial A');
    $b = teamBusiness('Trial B');
    $a['organization']->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $b['organization']->forceFill(['trial_ends_at' => now()->addDays(5)])->save();
    app(BillingLifecycle::class)->expireTrials();

    expect($a['organization']->fresh()->trial_expired_at)->not->toBeNull()->and($b['organization']->fresh()->trial_expired_at)->toBeNull()
        ->and(ownerNotices($a['owner'], 'trial_ended'))->toHaveCount(1)->and(ownerNotices($b['owner'], 'trial_ended'))->toHaveCount(0);

    // A webhook for one business cannot change another's subscription.
    $stripeFor = Organization::find($other->id)->billing_customer_id;
    webhook(stripeEvent('customer.subscription.updated', ['id' => $mine->provider_subscription_id, 'customer' => $stripeFor]))->assertStatus(500);
    expect($mine->fresh()->organization_id)->toBe($this->organization->id);
});

// ─── Workflow: Trial → Expiring → Ends → Restricted → Choose Plan → Active ───────────────

test('workflow: trial → expiring → ends → restricted → choose plan → active', function () {
    app(BillingService::class)->startSignupTrial($this->organization);
    $org = $this->organization->fresh();
    Customer::factory()->count(130)->for($this->organization)->create();

    // Trial: Starter limits, no banner, no notices.
    expectConsistent($this->owner, 'starter', null);
    expect(banner($org))->toBeNull()->and(ownerNotices($this->owner))->toHaveCount(0);

    // Trial expiring: the reminder goes out once and the banner appears.
    $this->travel(11)->days(); // 3 days left
    $this->artisan('billing:send-trial-reminders')->expectsOutputToContain('1 trial reminder(s) sent.');
    $this->artisan('billing:send-trial-reminders')->expectsOutputToContain('0 trial reminder(s) sent.');
    expect(ownerNotices($this->owner, 'trial_ending'))->toHaveCount(1)->and(banner($org))->toBe('trial_ending')
        ->and(app(EntitlementService::class)->plan($org)->key)->toBe('starter');
    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertSee('data-billing-banner="trial_ending"', false)->assertSee('Choose a Plan');

    // Trial ends: nothing happens until the job runs, but entitlements already lapse to Free.
    $this->travel(4)->days();
    expect(app(EntitlementService::class)->plan($org->fresh())->key)->toBe('free');
    $this->artisan('billing:expire-trials')->expectsOutputToContain('1 trial(s) expired.');

    // Restricted: Free limits, existing data intact, adding refused with a way forward, owner told.
    $org = $org->fresh();
    expect($org->trial_expired_at)->not->toBeNull()->and(ownerNotices($this->owner, 'trial_ended'))->toHaveCount(1)
        ->and(audit($org, 'trial_expired')->count())->toBe(1)->and(audit($org, 'billing_restriction_applied')->count())->toBe(1)
        ->and(Customer::where('organization_id', $org->id)->count())->toBe(131)
        ->and(app(EntitlementService::class)->canCreateCustomer($org))->toBeFalse()->and(banner($org))->toBe('expired');
    expectConsistent($this->owner, 'free', null);
    Livewire::actingAs($this->owner->fresh())->test(CustomerForm::class)->set('first_name', 'A')->set('last_name', 'B')->set('email', 'blocked@example.com')->call('save')
        ->assertHasErrors('email')->assertSee('Upgrade Plan');

    // Choose plan: Stripe Checkout, paid, confirmed by webhook (the owner never returns).
    $plans = Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->assertSee('Choose Starter')->call('choosePlan', 'starter');
    $sessionId = array_key_first($this->stripe->sessions);
    $plans->assertRedirect('https://checkout.stripe.test/c/'.$sessionId);
    $remote = $this->stripe->completeCheckout($sessionId);
    $org = $org->fresh();
    webhook(stripeEvent('checkout.session.completed', ['id' => $sessionId, 'customer' => $org->billing_customer_id, 'subscription' => $remote['id']]))->assertOk();

    // Active: entitlements restored, restriction banner gone, adding works, no duplicate expiry.
    $subscription = Subscription::sole();
    expect($subscription->plan)->toBe('starter')->and($subscription->grantsAccess())->toBeTrue()
        ->and(app(EntitlementService::class)->canCreateCustomer($org->fresh()))->toBeTrue()->and(banner($org))->toBeNull();
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Active); // the trial already ran in QuoteFollow, so Stripe charges from the start

    $this->artisan('billing:expire-trials')->expectsOutputToContain('0 trial(s) expired.');
    Livewire::actingAs($this->owner->fresh())->test(CustomerForm::class)->set('first_name', 'A')->set('last_name', 'B')->set('email', 'allowed@example.com')->call('save')->assertHasNoErrors();
    expect(audit($org, 'trial_expired')->count())->toBe(1)->and(Customer::where('organization_id', $org->id)->count())->toBe(132);
});

// ─── Workflow: Active → Payment Failed → Past Due → Grace → (Recovered → Active | Grace Ends → Restricted) ───

test('workflow: active → payment failed → past due → grace → recovered or restricted', function () {
    // Two businesses fail to pay on the same day and then take different paths.
    $recovers = ['owner' => $this->owner, 'organization' => $this->organization];
    ['owner' => $lapsesOwner, 'organization' => $lapses] = teamBusiness('Lapsing Co');
    $lapses->forceFill(['trial_ends_at' => null])->save();
    $a = paying($recovers['owner']);
    $b = paying($lapsesOwner);
    Customer::factory()->count(150)->for($lapses)->create(); // fine on Starter, over Free

    // Active → Payment failed → Past due.
    foreach ([$a, $b] as $subscription) {
        stripeSays($subscription, ['status' => 'past_due']);
        tellApp('invoice.payment_failed', $subscription);
    }

    foreach ([$recovers['organization'], $lapses] as $organization) {
        $subscription = Subscription::where('organization_id', $organization->id)->sole();
        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)->and($subscription->inGracePeriod())->toBeTrue()
            ->and($subscription->graceEndsAt()->isSameDay(now()->addDays(7)))->toBeTrue()
            ->and(audit($organization, 'payment_failed')->count())->toBe(1)
            ->and(app(EntitlementService::class)->plan($organization)->key)->toBe('starter') // grace: normal access
            ->and(banner($organization))->toBe('past_due');
    }

    // Grace period, day 3: nothing changes, and a retry that fails again adds no second notice.
    $this->travel(3)->days();
    tellApp('invoice.payment_failed', $a, 'evt_retry_a');
    expect(app(BillingLifecycle::class)->checkGracePeriods())->toMatchArray(['restricted' => 0, 'recovered' => 0])
        ->and(ownerNotices($recovers['owner'], 'payment_failed'))->toHaveCount(1);

    // Branch 1: payment recovered → Active.
    stripeSays($a, ['status' => 'active']);
    tellApp('invoice.paid', $a);
    $a = $a->fresh();
    expect($a->status)->toBe(SubscriptionStatus::Active)->and($a->past_due_since)->toBeNull()->and($a->restricted_at)->toBeNull()
        ->and(audit($recovers['organization'], 'payment_recovered')->count())->toBe(1)
        ->and(ownerNotices($recovers['owner'], 'payment_recovered'))->toHaveCount(1)
        ->and(banner($recovers['organization']))->toBeNull();
    expectConsistent($recovers['owner'], 'starter', SubscriptionStatus::Active);

    // Branch 2: grace ends with the payment still failing → Restricted.
    $this->travel(5)->days(); // day 8
    $result = app(BillingLifecycle::class)->checkGracePeriods();
    $b = $b->fresh();
    expect($result)->toMatchArray(['restricted' => 1, 'recovered' => 0])
        ->and($b->status)->toBe(SubscriptionStatus::PastDue)->and($b->restricted_at)->not->toBeNull()->and($b->grantsAccess())->toBeFalse()
        ->and(audit($lapses, 'billing_restriction_applied')->sole()->data)->toMatchArray(['reason' => 'grace_expired', 'plan' => 'free'])
        ->and(ownerNotices($lapsesOwner, 'billing_restricted'))->toHaveCount(1)
        ->and(app(EntitlementService::class)->plan($lapses)->key)->toBe('free')
        ->and(app(EntitlementService::class)->canCreateCustomer($lapses))->toBeFalse()
        ->and(Customer::where('organization_id', $lapses->id)->count())->toBe(151) // nothing deleted
        ->and(banner($lapses))->toBe('restricted');

    // The recovered business was never touched by the grace check.
    expect(audit($recovers['organization'], 'billing_restriction_applied')->count())->toBe(0)->and(app(EntitlementService::class)->plan($recovers['organization'])->key)->toBe('starter');

    // And a restricted business can still come back by paying.
    stripeSays($b, ['status' => 'active']);
    tellApp('invoice.paid', $b);
    expect(app(EntitlementService::class)->plan($lapses)->key)->toBe('starter')->and(audit($lapses, 'billing_restriction_lifted')->count())->toBe(1)->and(banner($lapses))->toBeNull();
});

// ─── Workflow: Active → Cancel Requested → Cancel at Period End → Period Ends → Cancelled ───

test('workflow: active → cancel requested → cancel at period end → period ends → cancelled', function () {
    $subscription = paying($this->owner);
    $periodEnd = $subscription->current_period_end;
    Customer::factory()->count(150)->for($this->organization)->create();

    // Active: no banner, nothing pending.
    expect(banner($this->organization))->toBeNull();

    // Cancel requested: scheduled for the period end, not immediate.
    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('cancelSubscription')->assertSee('remains active until');
    $subscription = $subscription->fresh();
    expect($subscription->cancel_at_period_end)->toBeTrue()->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($this->stripe->subscriptions[$subscription->provider_subscription_id]['cancel_at_period_end'])->toBeTrue()
        ->and(audit($this->organization, 'subscription_cancelled')->count())->toBe(1)
        ->and(ownerNotices($this->owner, 'subscription_cancelling'))->toHaveCount(1);

    // Cancel at period end: the paid plan keeps working until the end, with a banner and a way back.
    $this->travel(10)->days();
    expect(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')->and($subscription->fresh()->grantsAccess())->toBeTrue()
        ->and(banner($this->organization))->toBe('cancel_scheduled')
        ->and(app(BillingLifecycle::class)->checkGracePeriods()['restricted'])->toBe(0);
    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertSee('Your subscription ends on')->assertSee('Resume Subscription');
    expectConsistent($this->owner, 'starter', SubscriptionStatus::Active);

    // Period ends: access stops at that moment even before Stripe's webhook arrives (and nothing is deleted)...
    $this->travelTo($periodEnd->copy()->addMinute());
    expect($subscription->fresh()->grantsAccess())->toBeFalse()->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free')
        ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(151);

    // ...and Stripe's deletion event (or the hourly sync) records the final state.
    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    tellApp('customer.subscription.deleted', $subscription);

    // Cancelled.
    $subscription = $subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)->and($subscription->ended_at)->not->toBeNull()
        ->and(app(BillingService::class)->currentSubscription($this->organization))->toBeNull()
        ->and(audit($this->organization, 'subscription_ended')->count())->toBe(1)
        ->and(ownerNotices($this->owner, 'subscription_ended'))->toHaveCount(1)
        ->and(ownerNotices($this->owner, 'subscription_ended')->first()->data['url'])->toBe(route('settings.billing.plans'))
        ->and(banner($this->organization))->toBe('expired')
        ->and(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeFalse() // Free limits; existing data stays
        ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(151);
    expectConsistent($this->owner, 'free', null);

    // The hourly sync and a repeated webhook change nothing more.
    app(BillingService::class)->refreshAll();
    tellApp('customer.subscription.deleted', $subscription);
    expect(audit($this->organization, 'subscription_ended')->count())->toBe(1)->and(ownerNotices($this->owner, 'subscription_ended'))->toHaveCount(1);

    // The owner can start again from the Plans page.
    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->assertSee('Choose Starter');
});

test('a cancellation can be taken back any time before the period ends', function () {
    $subscription = paying($this->owner);
    $page = Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('cancelSubscription');
    $this->travel(20)->days();

    $page->call('resumeSubscription');

    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse()->and(banner($this->organization))->toBeNull()
        ->and(audit($this->organization, 'subscription_resumed')->count())->toBe(1);
    $this->travel(30)->days(); // well past the old period end
    app(BillingService::class)->refreshAll();
    expect(app(EntitlementService::class)->plan($this->organization->fresh())->key)->toBe('starter');
});

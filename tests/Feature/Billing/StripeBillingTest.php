<?php

use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\BillingException;
use App\Livewire\Settings\BillingOverview;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Services\Billing\BillingProviderManager;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'organization' => $this->organization] = teamBusiness();
    $this->stripe = FakeStripe::install();
    $this->billing = app(BillingService::class);
    $this->entitlements = app(EntitlementService::class);
});

/**
 * Owner goes through hosted checkout for a plan and comes back to the success URL.
 */
function checkoutAndPay(string $plan): Subscription
{
    $session = app(BillingService::class)->startCheckout(test()->owner, $plan, 'https://app.test/settings/billing?checkout=success&session_id={CHECKOUT_SESSION_ID}', 'https://app.test/settings/billing?checkout=cancelled');
    test()->stripe->completeCheckout($session->id);

    return app(BillingService::class)->completeCheckout(test()->owner->fresh(), $session->id);
}

// Checkout

test('checkout uses a Stripe hosted page for the plan price with organization metadata', function () {
    $session = $this->billing->startCheckout($this->owner, 'starter', 'https://app.test/ok?session_id={CHECKOUT_SESSION_ID}', 'https://app.test/cancel');

    expect($session->url)->toStartWith('https://checkout.stripe.test/c/')
        ->and($this->organization->fresh()->billing_customer_id)->toStartWith('cus_')
        ->and($this->organization->fresh()->billing_provider)->toBe('stripe');

    $request = $this->stripe->requestsTo('POST', 'checkout/sessions')[0]['data'];
    expect($request)->toMatchArray([
        'mode' => 'subscription',
        'customer' => $this->organization->fresh()->billing_customer_id,
        'client_reference_id' => (string) $this->organization->id,
        'success_url' => 'https://app.test/ok?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://app.test/cancel',
    ])->and($request['line_items'][0])->toEqual(['price' => 'price_starter', 'quantity' => 1])
        ->and($request['metadata']['organization_id'])->toBe((string) $this->organization->id);

    // The customer is created once, with an idempotency key; no card data is ever sent from QuoteFollow.
    $customerCall = $this->stripe->requestsTo('POST', 'customers')[0];
    expect($customerCall['headers']['Idempotency-Key'][0])->toBe("quotefollow-customer-{$this->organization->id}")
        ->and(json_encode($this->stripe->requests))->not->toMatch('/card|cvc|exp_month|number/i');

    $this->billing->startCheckout($this->owner->fresh(), 'pro', 'https://app.test/ok', 'https://app.test/cancel');
    expect($this->stripe->requestsTo('POST', 'customers'))->toHaveCount(1);
});

test('the billing page sends the owner to Stripe checkout and records the subscription on return', function () {
    Livewire::actingAs($this->owner)->test(BillingOverview::class)
        ->assertSee('Start 14-day free trial')
        ->call('choosePlan', 'pro')
        ->assertRedirect('https://checkout.stripe.test/c/'.array_key_first($this->stripe->sessions));

    $sessionId = array_key_first($this->stripe->sessions);
    expect($this->stripe->sessions[$sessionId]['success_url'])->toBe(route('settings.billing').'?checkout=success&session_id={CHECKOUT_SESSION_ID}');

    // Not paid yet: nothing recorded.
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($this->owner->fresh())->test(BillingOverview::class)
        ->assertSee('The checkout isn')->assertSet('statusType', 'error');
    expect(Subscription::count())->toBe(0);

    $this->stripe->completeCheckout($sessionId);
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($this->owner->fresh())->test(BillingOverview::class)
        ->assertSee('Your Pro trial has started.');
    // Reloading the success page doesn't create a second subscription.
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($this->owner->fresh())->test(BillingOverview::class);

    expect(Subscription::count())->toBe(1)->and(OrganizationActivity::where('action', 'subscription_started')->count())->toBe(1);

    Livewire::withQueryParams(['checkout' => 'cancelled'])->actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee('Nothing was charged.');
});

// Trial and subscription creation

test('the first subscription gets a 14-day trial and a business never gets a second one', function () {
    $subscription = checkoutAndPay('starter');

    expect($this->stripe->requestsTo('POST', 'checkout/sessions')[0]['data']['subscription_data']['trial_period_days'])->toEqual(14)
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->onTrial())->toBeTrue()
        ->and($subscription->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($this->organization->fresh()->trial_used_at)->not->toBeNull()
        ->and($this->entitlements->plan($this->organization)->key)->toBe('starter');

    // Cancel, then subscribe again: no trial this time.
    $this->billing->cancel($this->owner->fresh(), atPeriodEnd: false);
    expect($this->billing->trialDaysFor($this->organization->fresh()))->toBe(0);
    checkoutAndPay('pro');

    expect($this->stripe->requestsTo('POST', 'checkout/sessions')[1]['data'])->not->toHaveKey('subscription_data.trial_period_days')
        ->and($this->stripe->requestsTo('POST', 'checkout/sessions')[1]['data']['subscription_data'])->not->toHaveKey('trial_period_days')
        ->and(Subscription::latest('id')->first()->status)->toBe(SubscriptionStatus::Active);
});

test('a completed checkout creates the local subscription mirroring Stripe', function () {
    $subscription = checkoutAndPay('pro');
    $remote = $this->stripe->subscriptions[$subscription->provider_subscription_id];

    expect($subscription->organization_id)->toBe($this->organization->id)
        ->and($subscription->provider)->toBe('stripe')
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->current_period_end->getTimestamp())->toBe($remote['items']['data'][0]['current_period_end'])
        ->and($this->organization->fresh()->currentSubscription->id)->toBe($subscription->id);

    // One current subscription: a second checkout is refused (change plan instead).
    expect(fn () => $this->billing->startCheckout($this->owner->fresh(), 'starter', 'https://a', 'https://b'))->toThrow(BillingException::class, 'Change its plan instead');
});

test('a checkout of another organization cannot be claimed', function () {
    ['owner' => $otherOwner] = teamBusiness('Other Co');
    $theirs = $this->billing->startCheckout($otherOwner, 'pro', 'https://a', 'https://b');
    $this->stripe->completeCheckout($theirs->id);

    expect(fn () => $this->billing->completeCheckout($this->owner, $theirs->id))->toThrow(BillingException::class, 'belongs to another business')
        ->and(Subscription::count())->toBe(0);
});

// Upgrade

test('upgrades apply immediately with Stripe proration', function () {
    $subscription = checkoutAndPay('starter');

    $upgraded = $this->billing->changePlan($this->owner->fresh(), 'pro');

    $update = collect($this->stripe->requestsTo('POST', 'subscriptions/'))->last()['data'];
    expect($update['items'][0]['price'])->toBe('price_pro')
        ->and($update['proration_behavior'])->toBe('create_prorations')
        ->and($upgraded->plan)->toBe('pro')
        ->and($upgraded->id)->toBe($subscription->id)
        ->and($this->entitlements->plan($this->organization)->key)->toBe('pro')
        ->and(OrganizationActivity::where('action', 'subscription_plan_changed')->sole()->data['changes']['Plan'])->toEqual(['from' => 'starter', 'to' => 'pro']);

    // Free → Starter / Free → Pro go through checkout (tested above).
});

// Downgrade

test('Pro to Starter is scheduled for the end of the paid period and keeps all data', function () {
    $subscription = checkoutAndPay('pro');
    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id); // trial over, paid period running
    $subscription = $this->billing->refresh($this->organization->fresh());
    Customer::factory()->count(600)->for($this->organization)->create(); // more than Starter allows

    $page = Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee("You're over this plan's limits", false);
    $page->call('choosePlan', 'starter')->assertSee("You're on Pro until");

    $subscription->refresh();
    $schedule = $this->stripe->schedules[$subscription->provider_schedule_id];
    expect($subscription->plan)->toBe('pro')
        ->and($subscription->scheduled_plan)->toBe('starter')
        ->and($subscription->scheduled_change_at->getTimestamp())->toBe($this->stripe->subscriptions[$subscription->provider_subscription_id]['items']['data'][0]['current_period_end'])
        ->and($schedule['phases'][1]['items'][0]['price'])->toBe('price_starter')
        ->and($this->entitlements->plan($this->organization)->key)->toBe('pro'); // still Pro until then

    // The period ends at Stripe; synchronization picks up the new plan. Nothing was deleted.
    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    $this->artisan('billing:sync-subscriptions')->expectsOutputToContain('1 subscription(s) synced')->assertSuccessful();

    expect($subscription->fresh()->plan)->toBe('starter')
        ->and($subscription->fresh()->scheduled_plan)->toBeNull()
        ->and($this->entitlements->plan($this->organization)->key)->toBe('starter')
        ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(601)
        ->and($this->entitlements->allows($this->organization, LimitKey::Customers))->toBeFalse();
});

test('choosing the current plan again drops a scheduled downgrade', function () {
    checkoutAndPay('pro');
    $this->billing->changePlan($this->owner->fresh(), 'starter');

    $kept = $this->billing->changePlan($this->owner->fresh(), 'pro');

    expect($kept->scheduled_plan)->toBeNull()->and($kept->provider_schedule_id)->toBeNull()
        ->and($this->stripe->schedules)->toBe([])
        ->and($this->stripe->requestsTo('POST', 'subscription_schedules/'))->not->toBeEmpty();
});

test('Starter to Free cancels at the period end', function () {
    $subscription = checkoutAndPay('starter');

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)
        ->call('choosePlan', 'free')
        ->assertSee('Your subscription will remain active until');

    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue()
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($this->entitlements->plan($this->organization)->key)->toBe('starter');
});

// Cancellation and resume

test('cancellation defaults to the end of the period and shows until when it stays active', function () {
    $subscription = checkoutAndPay('pro');

    $page = Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('cancelSubscription');

    $cancelled = $subscription->fresh();
    $date = $this->organization->formatDate($cancelled->current_period_end);
    $page->assertSee("Your subscription will remain active until {$date}.")->assertSee('Resume subscription');

    expect(collect($this->stripe->requestsTo('POST', 'subscriptions/'))->last()['data']['cancel_at_period_end'])->toBe('true')
        ->and($this->stripe->requestsTo('DELETE', 'subscriptions/'))->toBe([])
        ->and($cancelled->cancel_at_period_end)->toBeTrue()
        ->and($this->entitlements->plan($this->organization)->key)->toBe('pro');

    // After the period: cancelled at Stripe, synced locally, back on Free.
    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    $this->billing->refresh($this->organization->fresh());
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)->and($this->entitlements->plan($this->organization)->key)->toBe('free');
});

test('a cancellation scheduled for the period end can be reversed', function () {
    $subscription = checkoutAndPay('starter');
    $this->billing->cancel($this->owner->fresh());

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('resumeSubscription')->assertSee('Your subscription will continue.');

    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse()
        ->and($this->stripe->subscriptions[$subscription->provider_subscription_id]['cancel_at_period_end'])->toBeFalse()
        ->and(fn () => $this->billing->resume($this->owner->fresh()))->toThrow(BillingException::class, 'not scheduled to cancel');
});

// Billing portal

test('payment methods, billing details and invoices are managed in the Stripe billing portal', function () {
    expect(fn () => $this->billing->portalUrl($this->owner, 'https://app.test'))->toThrow(BillingException::class, 'Choose a plan first');

    checkoutAndPay('starter');

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)
        ->call('openPortal')->assertRedirect('https://billing.stripe.test/p/session_1');

    expect($this->stripe->requestsTo('POST', 'billing_portal/sessions')[0]['data'])->toBe([
        'customer' => $this->organization->fresh()->billing_customer_id,
        'return_url' => route('settings.billing', ['portal' => 1]),
    ]);

    // Coming back from the portal re-syncs the subscription.
    $before = count($this->stripe->requestsTo('GET', 'subscriptions/'));
    Livewire::withQueryParams(['portal' => 1])->actingAs($this->owner->fresh())->test(BillingOverview::class);
    expect(count($this->stripe->requestsTo('GET', 'subscriptions/')))->toBe($before + 1);

    // Managers can't open billing at all.
    Livewire::actingAs($this->manager)->test(BillingOverview::class)->assertForbidden();
});

// Provider failures

test('provider failures show a safe message and change nothing locally', function () {
    $subscription = checkoutAndPay('starter');
    Log::spy();

    $this->stripe->failWith(500);
    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('choosePlan', 'pro')
        ->assertSee('The payment provider is having trouble right now.')->assertDontSee('req_secret_detail');
    expect($subscription->fresh()->plan)->toBe('starter');

    $this->stripe->failWith(400, 'invalid_request_error');
    expect(fn () => $this->billing->cancel($this->owner->fresh()))->toThrow(BillingException::class, 'rejected the request');
    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse();

    $this->stripe->failToConnect();
    expect(fn () => $this->billing->portalUrl($this->owner->fresh(), 'https://app.test'))->toThrow(BillingException::class, "can't be reached");

    // Secrets never appear in logs.
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode($context), 'sk_test_fake_secret'));

    $this->stripe->recover();
    expect($this->billing->changePlan($this->owner->fresh(), 'pro')->plan)->toBe('pro');
});

test('missing Stripe configuration fails safely', function () {
    config(['services.stripe.secret' => null]);
    app()->forgetInstance(BillingProviderManager::class);
    expect(fn () => app(BillingService::class)->startCheckout($this->owner, 'pro', 'https://a', 'https://b'))->toThrow(BillingException::class, "Online payments aren't set up yet.");

    FakeStripe::install();
    config(['billing.plans.pro.provider_price_id' => null]);
    app()->forgetInstance(PlanCatalog::class);
    app()->forgetInstance(BillingProviderManager::class);
    expect(fn () => app(BillingService::class)->startCheckout($this->owner, 'pro', 'https://a', 'https://b'))->toThrow(BillingException::class, "isn't available for purchase yet")
        ->and(Organization::find($this->organization->id)->billing_customer_id)->not->toBeNull(); // the customer itself is fine to keep
});

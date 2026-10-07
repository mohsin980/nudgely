<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Livewire\Settings\BillingOverview;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

/**
 * Sign up → choose plan → Stripe Checkout (14-day trial) → Stripe confirms → active
 * subscription → upgrade / downgrade / cancel / resume, through the real pages (only Stripe is faked).
 */
test('a business goes from sign-up to a managed Stripe subscription', function () {
    $this->withoutVite();
    $stripe = FakeStripe::install();
    $entitlements = app(EntitlementService::class);

    // Business signs up: no subscription, Free plan, no trial running yet.
    $this->post('/register', ['business_name' => 'Dallas HVAC', 'name' => 'John Smith', 'email' => 'john@dallashvac.com',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertRedirect();
    $owner = User::where('email', 'john@dallashvac.com')->sole();
    $org = $owner->organization;
    expect(Subscription::count())->toBe(0)->and($entitlements->plan($org)->key)->toBe('free')->and($org->trial_used_at)->toBeNull();

    // Choose plan → Stripe Checkout (the page offers the 14-day trial).
    $page = Livewire::actingAs($owner)->test(BillingOverview::class)->assertSee('Start 14-day free trial')->call('choosePlan', 'starter');
    $sessionId = array_key_first($stripe->sessions);
    $page->assertRedirect("https://checkout.stripe.test/c/{$sessionId}");
    expect($stripe->sessions[$sessionId]['subscription_data']['trial_period_days'])->toEqual(14);

    // Stripe confirms: the customer paid/entered a card on Stripe's page; we read the result back.
    $stripe->completeCheckout($sessionId);
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($owner->fresh())->test(BillingOverview::class)->assertSee('Your Starter trial has started.');
    $subscription = Subscription::sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)->and($subscription->provider)->toBe('stripe')
        ->and($entitlements->plan($org)->key)->toBe('starter')->and($org->fresh()->trial_used_at)->not->toBeNull();

    // Trial ends at Stripe, sync → Active.
    $stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    $this->artisan('billing:sync-subscriptions')->assertSuccessful();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    // Upgrade (now), downgrade (period end), cancel, resume.
    $manage = Livewire::actingAs($owner->fresh())->test(BillingOverview::class);
    $manage->call('choosePlan', 'pro');
    expect($subscription->fresh()->plan)->toBe('pro')->and($entitlements->plan($org)->key)->toBe('pro');

    $manage->call('choosePlan', 'starter')->assertSee("You're on Pro until");
    expect($subscription->fresh()->scheduled_plan)->toBe('starter')->and($entitlements->plan($org)->key)->toBe('pro');

    $manage->call('cancelSubscription')->assertSee('Your subscription will remain active until');
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue()->and($subscription->fresh()->scheduled_plan)->toBeNull();

    $manage->call('resumeSubscription')->assertSee('Your subscription will continue.');
    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse()->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

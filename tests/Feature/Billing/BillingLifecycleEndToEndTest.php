<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Livewire\Settings\BillingOverview;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

function signUp(string $business, string $email): User
{
    test()->post('/register', ['business_name' => $business, 'name' => 'Owner', 'email' => $email,
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertRedirect();

    return User::where('email', $email)->sole();
}

/**
 * Sign up → 14-day card-free trial → choose plan → Stripe Checkout → Stripe confirms → active
 * subscription → upgrade / downgrade / cancel / resume, through the real pages (only Stripe is faked).
 */
test('a business goes from sign-up through a free trial to a managed Stripe subscription', function () {
    $this->withoutVite();
    $stripe = FakeStripe::install();
    $entitlements = app(EntitlementService::class);

    // Sign up: a 14-day trial of Starter starts at once, with no card and no subscription.
    $owner = signUp('Dallas HVAC', 'john@dallashvac.com');
    $org = $owner->organization;
    $trial = $entitlements->trial($org);
    expect(Subscription::count())->toBe(0)->and($org->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($trial['plan']->key)->toBe('starter')->and($trial['days_left'])->toBe(14)
        ->and($entitlements->plan($org)->key)->toBe('starter')->and($stripe->requests)->toBe([]);

    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('Your free Starter trial: 14 days left.');

    // Choose plan → Stripe Checkout. The trial already ran inside QuoteFollow, so Stripe adds none.
    Livewire::actingAs($owner)->test(BillingOverview::class)->assertSee('Your free Starter trial ends on')->assertSee('Choose Pro')->call('choosePlan', 'pro');
    $sessionId = array_key_first($stripe->sessions);
    expect($stripe->sessions[$sessionId]['subscription_data'])->not->toHaveKey('trial_period_days');

    // Stripe confirms; the subscription is recorded, active at once, and ends the sign-up trial.
    $stripe->completeCheckout($sessionId);
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($owner->fresh())->test(BillingOverview::class)->assertSee("You're now on the Pro plan.");
    $subscription = Subscription::sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->plan)->toBe('pro')
        ->and($entitlements->trial($org->fresh()))->toBeNull()->and($entitlements->plan($org)->key)->toBe('pro');

    // Downgrade (period end), upgrade back, cancel, resume.
    $manage = Livewire::actingAs($owner->fresh())->test(BillingOverview::class);
    $manage->call('choosePlan', 'starter')->assertSee("You're on Pro until");
    expect($subscription->fresh()->scheduled_plan)->toBe('starter')->and($entitlements->plan($org)->key)->toBe('pro');

    $manage->call('choosePlan', 'pro');
    expect($subscription->fresh()->scheduled_plan)->toBeNull();

    $manage->call('cancelSubscription')->assertSee('Your subscription will remain active until');
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();

    $manage->call('resumeSubscription')->assertSee('Your subscription will continue.');
    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse()->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('when the free trial ends without a plan the business moves to Free and nothing is deleted', function () {
    $this->withoutVite();
    $owner = signUp('Austin Plumbing', 'ana@austinplumbing.com');
    $org = $owner->organization;
    Customer::factory()->count(150)->for($org)->create(); // more than Free allows, fine on Starter

    $this->travel(13)->days();
    $entitlements = app(EntitlementService::class);
    expect($entitlements->plan($org->fresh())->key)->toBe('starter')->and($entitlements->trial($org->fresh())['days_left'])->toBe(1);

    $this->travel(2)->days();
    $org = $org->fresh();
    expect($entitlements->trial($org))->toBeNull()->and($entitlements->plan($org)->key)->toBe('free')
        ->and(Customer::where('organization_id', $org->id)->count())->toBe(150)
        ->and($entitlements->summary($org)['customers']['over'])->toBeTrue();

    Livewire::actingAs($owner->fresh())->test(BillingOverview::class)->assertSee('Free')->assertSee('Choose Starter');
});

test('a business gets one trial only and trials are per business', function () {
    $this->withoutVite();
    $stripe = FakeStripe::install();
    $one = signUp('One Co', 'one@example.com');
    Auth::logout();
    $two = signUp('Two Co', 'two@example.com');
    expect($one->organization_id)->not->toBe($two->organization_id)
        ->and($one->organization->fresh()->trial_ends_at)->not->toBeNull()->and($two->organization->fresh()->trial_ends_at)->not->toBeNull();

    // Starting the trial again (e.g. a repeated call) changes nothing.
    $endsAt = $one->organization->fresh()->trial_ends_at;
    $this->travel(5)->days();
    app(BillingService::class)->startSignupTrial($one->organization->fresh());
    expect($one->organization->fresh()->trial_ends_at->equalTo($endsAt))->toBeTrue()
        ->and(app(BillingService::class)->trialDaysFor($one->organization->fresh()))->toBe(0);

    // The other business's trial and plan are unaffected by this one's checkout.
    $session = app(BillingService::class)->startCheckout($one->fresh(), 'starter', 'https://a', 'https://b');
    $stripe->completeCheckout($session->id);
    app(BillingService::class)->completeCheckout($one->fresh(), $session->id);
    expect(app(EntitlementService::class)->trial($two->organization->fresh()))->not->toBeNull()
        ->and(app(EntitlementService::class)->trial($one->organization->fresh()))->toBeNull();
});

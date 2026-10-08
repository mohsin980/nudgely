<?php

use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\BillingPlans;
use App\Livewire\Settings\BillingUsage;
use App\Models\BillingWebhookEvent;
use App\Models\Customer;
use App\Models\OrganizationActivity;
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
    Livewire::actingAs($owner)->test(BillingOverview::class)->assertSee('Your free Starter trial ends on');
    Livewire::actingAs($owner)->test(BillingPlans::class)->assertSee('Choose Pro')->call('choosePlan', 'pro');
    $sessionId = array_key_first($stripe->sessions);
    expect($stripe->sessions[$sessionId]['subscription_data'])->not->toHaveKey('trial_period_days');

    // Stripe confirms; the subscription is recorded, active at once, and ends the sign-up trial.
    $stripe->completeCheckout($sessionId);
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($owner->fresh())->test(BillingOverview::class)->assertSee("You're now on the Pro plan.");
    $subscription = Subscription::sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->plan)->toBe('pro')
        ->and($entitlements->trial($org->fresh()))->toBeNull()->and($entitlements->plan($org)->key)->toBe('pro');

    // Downgrade (period end), upgrade back, cancel, resume.
    $manage = Livewire::actingAs($owner->fresh())->test(BillingPlans::class);
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

    Livewire::actingAs($owner->fresh())->test(BillingPlans::class)->assertSee('Free')->assertSee('Choose Starter');
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

test('a business that hits a limit upgrades through Stripe and the new plan unlocks the feature, even if the owner never returns', function () {
    $this->withoutVite();
    $stripe = FakeStripe::install();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    ['owner' => $owner, 'manager' => $manager, 'organization' => $org] = teamBusiness('Limit Co');
    $org->forceFill(['trial_ends_at' => null])->save();
    tightFreePlan(['customers' => 2]); // the helper's customer + one more fill the Free plan
    $add = fn ($by, string $email) => Livewire::actingAs($by->fresh())->test(CustomerForm::class)
        ->set('first_name', 'New')->set('last_name', 'Person')->set('email', $email)->call('save');

    // 1. Limit reached: the form blocks the customer, says why, and offers the upgrade (to the owner only).
    $add($owner, 'one@example.com')->assertHasNoErrors();
    $blocked = $add($owner, 'two@example.com')->assertHasErrors('email')->assertSee('Your Free plan allows up to 2 customers')->assertSee('Upgrade to Starter')->assertSee('Upgrade Plan');
    expect($blocked->html())->toContain(route('settings.billing.plans'))
        ->and(Customer::where('organization_id', $org->id)->count())->toBe(2);
    $add($manager, 'two@example.com')->assertHasErrors('email')->assertDontSee('Upgrade Plan');

    // 2. The usage page agrees, and the plans page offers Starter.
    Livewire::actingAs($owner->fresh())->test(BillingUsage::class)->assertSee('2 / 2')->assertSee('Upgrade Plan');
    $plans = Livewire::actingAs($owner->fresh())->test(BillingPlans::class)->assertSee('Start 14-day free trial');

    // 3. Select the plan: straight to Stripe Checkout (nothing is recorded locally yet).
    $plans->call('choosePlan', 'starter')->assertRedirect('https://checkout.stripe.test/c/'.array_key_first($stripe->sessions));
    $sessionId = array_key_first($stripe->sessions);
    expect(Subscription::count())->toBe(0)->and(app(EntitlementService::class)->plan($org)->key)->toBe('free');

    // 4. The customer pays on Stripe and closes the tab; Stripe's webhooks are the only thing that tells us.
    $remote = $stripe->completeCheckout($sessionId);
    $customerId = $org->fresh()->billing_customer_id;
    webhook(stripeEvent('checkout.session.completed', ['id' => $sessionId, 'customer' => $customerId, 'subscription' => $remote['id']], 'evt_e2e_1'))->assertOk();
    webhook(stripeEvent('customer.subscription.created', ['id' => $remote['id'], 'customer' => $customerId], 'evt_e2e_2'))->assertOk();
    webhook(stripeEvent('checkout.session.completed', ['id' => $sessionId, 'customer' => $customerId, 'subscription' => $remote['id']], 'evt_e2e_1'))->assertOk()->assertJson(['duplicate' => true]);

    // 5. The subscription is recorded once and the entitlements change straight away.
    $subscription = Subscription::sole();
    $entitlements = app(EntitlementService::class);
    expect($subscription->organization_id)->toBe($org->id)->and($subscription->plan)->toBe('starter')->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($entitlements->plan($org)->key)->toBe('starter')->and($entitlements->limit($org, LimitKey::Customers))->toBe(500)
        ->and($entitlements->canCreateCustomer($org))->toBeTrue();

    // 6. The blocked action now works, and nothing was removed or duplicated along the way.
    $add($owner, 'two@example.com')->assertHasNoErrors();
    expect(Customer::where('organization_id', $org->id)->count())->toBe(3);
    Livewire::actingAs($owner->fresh())->test(BillingUsage::class)->assertSee('3 / 500')->assertDontSee("You've reached your customer limit.", false);
    Livewire::actingAs($owner->fresh())->test(BillingOverview::class)->assertSee('Starter')->assertSee('Trialing');

    // 7. If the owner does return from Checkout later, nothing changes.
    Livewire::withQueryParams(['checkout' => 'success', 'session_id' => $sessionId])->actingAs($owner->fresh())->test(BillingOverview::class);
    expect(Subscription::count())->toBe(1)->and(OrganizationActivity::where('action', 'subscription_started')->count())->toBe(1)
        ->and(BillingWebhookEvent::where('status', 'processed')->count())->toBe(2);
});

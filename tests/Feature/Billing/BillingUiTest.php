<?php

use App\Billing\PlanCatalog;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Settings\BillingHistory;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\BillingPlans;
use App\Livewire\Settings\BillingUsage;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness();
    $this->stripe = FakeStripe::install();
});

function paidPlan(User $owner, string $plan): Subscription
{
    $session = app(BillingService::class)->startCheckout($owner, $plan, 'https://app.test/ok', 'https://app.test/cancel');
    test()->stripe->completeCheckout($session->id);

    return app(BillingService::class)->completeCheckout($owner->fresh(), $session->id);
}

const BILLING_ROUTES = ['settings.billing', 'settings.billing.plans', 'settings.billing.usage', 'settings.billing.history'];

// ─── Routes and authorization ────────────────────────────────────────────────────────────

test('the four billing pages open for the owner and link to each other', function () {
    foreach (BILLING_ROUTES as $route) {
        $this->actingAs($this->owner)->get(route($route))->assertOk()->assertSee('Billing sections')->assertSee(route('settings.billing.usage'), false);
    }

    expect(route('settings.billing'))->toEndWith('/settings/billing')->and(route('settings.billing.plans'))->toEndWith('/settings/billing/plans')
        ->and(route('settings.billing.usage'))->toEndWith('/settings/billing/usage')->and(route('settings.billing.history'))->toEndWith('/settings/billing/history');
});

test('only the owner may see billing: managers, staff and visitors are turned away', function () {
    foreach (BILLING_ROUTES as $route) {
        $this->actingAs($this->manager)->get(route($route))->assertForbidden();
        $this->actingAs($this->staff)->get(route($route))->assertForbidden();
        auth()->logout();
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

test('billing actions are refused on the server for anyone but the owner', function () {
    paidPlan($this->owner, 'starter');

    foreach ([BillingOverview::class, BillingPlans::class] as $component) {
        Livewire::actingAs($this->manager)->test($component)->assertForbidden();
    }

    // Even a component that was somehow mounted by the owner can't be driven by someone else: the service refuses too.
    expect(fn () => app(BillingService::class)->cancel($this->manager))->toThrow(AuthorizationException::class)
        ->and(fn () => app(BillingService::class)->resume($this->staff))->toThrow(AuthorizationException::class)
        ->and(fn () => app(BillingService::class)->changePlan($this->manager, 'pro'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(BillingService::class)->portalUrl($this->manager, 'https://app.test'))->toThrow(AuthorizationException::class)
        ->and(Subscription::sole()->plan)->toBe('starter');
});

test('the settings menu lists Billing for the owner only', function () {
    $this->actingAs($this->owner)->get(route('settings.security'))->assertSee('Billing');
    $this->actingAs($this->manager)->get(route('settings.security'))->assertDontSee(route('settings.billing'), false);
});

// ─── Current plan ────────────────────────────────────────────────────────────────────────

test('the overview shows plan, price, status, billing period and next billing date', function () {
    $subscription = paidPlan($this->owner, 'pro');
    $this->stripe->advancePastPeriodEnd($subscription->provider_subscription_id);
    $subscription = app(BillingService::class)->refresh($this->organization->fresh());
    $next = $this->organization->formatDate($subscription->current_period_end);

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)
        ->assertSeeInOrder(['Current plan', 'Pro', '$79.00 / month', 'Active', 'Billing period', 'Next billing date', $next])
        ->assertSee('Change plan')->assertSee('Manage billing')->assertSee('Cancel subscription')
        ->assertDontSee('Resume subscription');
});

test('without a subscription the Free plan is shown with no billing date', function () {
    $this->organization->forceFill(['trial_ends_at' => null])->save();

    Livewire::actingAs($this->owner)->test(BillingOverview::class)
        ->assertSee('Free')->assertSee('No subscription')->assertDontSee('Next billing date')->assertDontSee('Manage billing')->assertDontSee('Cancel subscription');
});

test('prices and limits come from the plan configuration, not the views', function () {
    config(['billing.plans.pro.price_cents' => 9900, 'billing.plans.pro.limits.customers' => 2468]);
    app()->forgetInstance(PlanCatalog::class);

    Livewire::actingAs($this->owner)->test(BillingPlans::class)->assertSee('$99.00')->assertSee('2,468 customers')->assertDontSee('$79.00');
});

// ─── Plan selection ──────────────────────────────────────────────────────────────────────

test('the plans page shows every plan with price, limits, features and the right buttons', function () {
    paidPlan($this->owner, 'starter');

    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)
        ->assertSeeInOrder(['Free', 'Starter', 'Current plan', 'Pro'])
        ->assertSee('$29.00')->assertSee('$79.00')->assertSee('500 customers')->assertSee('10,000 outbound emails')
        ->assertSee('Team roles & permissions')->assertSee('Priority support')
        ->assertSee('Upgrade to Pro')->assertSee('Downgrade to Free')
        ->assertSee('Takes effect at the end of your billing period.')->assertSee('prorated');
});

test('a business without a subscription is offered checkout, with the trial stated honestly', function () {
    $this->organization->forceFill(['trial_ends_at' => null, 'trial_used_at' => null])->save();

    $page = Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->assertSee('Start 14-day free trial')->assertDontSee('Downgrade');
    $page->call('choosePlan', 'starter')->assertRedirect('https://checkout.stripe.test/c/'.array_key_first($this->stripe->sessions));
});

test('choosing a plan calls the billing service: upgrade now, downgrade scheduled', function () {
    $subscription = paidPlan($this->owner, 'starter');
    $page = Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class);

    $page->call('choosePlan', 'pro')->assertSee('Your Pro trial ends on');
    expect($subscription->fresh()->plan)->toBe('pro');

    $page->call('choosePlan', 'starter')->assertSee("You're on Pro until");
    expect($subscription->fresh()->scheduled_plan)->toBe('starter');

    $page->assertSee('Scheduled for')->assertSee('Keep Pro');
});

test('a downgrade warns when usage is above the smaller plan, and never deletes', function () {
    paidPlan($this->owner, 'pro');
    Customer::factory()->count(600)->for($this->organization)->create();

    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->assertSee("You're over this plan's limits", false)->assertSee('Nothing is deleted');
    expect(Customer::where('organization_id', $this->organization->id)->count())->toBe(601);
});

test('provider problems show a safe message', function () {
    paidPlan($this->owner, 'starter');
    $this->stripe->failWith(500);

    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->call('choosePlan', 'pro')->assertSee('The payment provider is having trouble right now.')->assertDontSee('req_secret_detail');
});

// ─── Cancellation ────────────────────────────────────────────────────────────────────────

test('a scheduled cancellation is explained and can be resumed', function () {
    $subscription = paidPlan($this->owner, 'starter');
    $page = Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('cancelSubscription');
    $until = $this->organization->formatDate($subscription->fresh()->current_period_end);

    $page->assertSee('Your subscription remains active until')->assertSee($until)->assertSee('Resume subscription')->assertDontSee('Cancel subscription')
        ->assertSeeHtml('data-next-billing')->assertSee('None');
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();

    $page->call('resumeSubscription')->assertDontSee('Your subscription remains active until')->assertSee('Cancel subscription');
    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse();
});

test('the plans page points a cancelling business to the resume button', function () {
    paidPlan($this->owner, 'starter');
    app(BillingService::class)->cancel($this->owner->fresh());

    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->assertSee('is set to end on')->assertDontSee('Downgrade to Free');
});

// ─── Usage ───────────────────────────────────────────────────────────────────────────────

test('the usage page shows used / limit with progress bars for every limit', function () {
    paidPlan($this->owner, 'starter');
    Customer::factory()->count(99)->for($this->organization)->create();

    Livewire::actingAs($this->owner->fresh())->test(BillingUsage::class)
        ->assertSeeInOrder(['Customers', '100 / 500', 'Automations', '0 / 15', 'Team members', '3 / 3', 'Outbound emails', '0 / 2,500', 'Estimates', '0 / 250'])
        ->assertSeeHtml('role="progressbar"')->assertSeeHtml('aria-valuenow="20"')
        ->assertSee('This billing period');
});

test('unlimited limits are shown without a bar', function () {
    config(['billing.plans.pro.limits.estimates' => null]);
    app()->forgetInstance(PlanCatalog::class);
    paidPlan($this->owner, 'pro');

    Livewire::actingAs($this->owner->fresh())->test(BillingUsage::class)->assertSee('0 / Unlimited');
});

test('a reached limit says so once, with an upgrade link, and a normal limit does not', function () {
    paidPlan($this->owner, 'starter'); // 3 seats, 3 members
    $page = Livewire::actingAs($this->owner->fresh())->test(BillingUsage::class);

    $page->assertSee("You've reached your team member limit.", false)->assertSee('Upgrade Plan')->assertDontSee("You've reached your customer limit.", false);
    expect(substr_count($page->html(), 'Upgrade Plan'))->toBe(1);

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee("You've reached your team member limit.", false)->assertSee('Upgrade Plan');
});

test('usage numbers belong to the signed-in organization only', function () {
    ['organization' => $other] = teamBusiness('Other Co');
    Customer::factory()->count(40)->for($other)->create();

    Livewire::actingAs($this->owner)->test(BillingUsage::class)->assertSee('1 / 100')->assertDontSee('41 / 100');
});

// ─── Payment method and invoices ─────────────────────────────────────────────────────────

test('only the card type, last four digits and expiry are shown', function () {
    $subscription = paidPlan($this->owner, 'starter');
    $customer = $this->organization->fresh()->billing_customer_id;
    $this->stripe->cards[$customer] = ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030, 'fingerprint' => 'fp_secret', 'number' => '4242424242424242'];

    $page = Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee('Visa ending 4242')->assertSee('expires 04/2030')->assertSee('Manage billing');

    expect($page->html())->not->toContain('4242424242424242')->not->toContain('fp_secret');
});

test('the payment section copes with no card and with provider trouble', function () {
    paidPlan($this->owner, 'starter');
    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee('No payment method on file.');

    app('cache')->flush();
    $this->stripe->failWith(500);
    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee("Payment details can't be loaded right now.")->assertSee('Current plan');
});

test('the manage billing button sends the owner to the provider portal', function () {
    paidPlan($this->owner, 'starter');

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->call('openPortal')->assertRedirect('https://billing.stripe.test/p/session_1');
});

test('invoices are listed with a link to view them', function () {
    paidPlan($this->owner, 'starter');
    $customer = $this->organization->fresh()->billing_customer_id;
    $this->stripe->invoices[$customer] = [
        ['id' => 'in_1', 'number' => 'QF-0002', 'created' => strtotime('2026-11-04'), 'total' => 7900, 'currency' => 'usd', 'status' => 'paid', 'hosted_invoice_url' => 'https://invoice.stripe.test/i/1', 'invoice_pdf' => 'https://invoice.stripe.test/i/1.pdf'],
        ['id' => 'in_0', 'number' => 'QF-0001', 'created' => strtotime('2026-10-04'), 'total' => 2900, 'currency' => 'usd', 'status' => 'paid', 'hosted_invoice_url' => null, 'invoice_pdf' => null],
    ];

    $page = Livewire::actingAs($this->owner->fresh())->test(BillingHistory::class)
        ->assertSeeInOrder(['QF-0002', '$79.00', 'Paid', 'View invoice', 'QF-0001', '$29.00']);

    expect(substr_count($page->html(), 'View invoice'))->toBe(1)->and($page->html())->toContain('rel="noopener noreferrer"');
});

test('with no invoices, or when the provider fails, the history page explains instead of breaking', function () {
    Livewire::actingAs($this->owner)->test(BillingHistory::class)->assertSee('No invoices yet');

    paidPlan($this->owner, 'starter');
    app('cache')->flush();
    $this->stripe->failWith(500);
    Livewire::actingAs($this->owner->fresh())->test(BillingHistory::class)->assertSee("Invoices can't be loaded right now.")->assertDontSee('req_secret_detail');
});

// ─── Organization isolation ──────────────────────────────────────────────────────────────

test('one business never sees another business\'s subscription, card or invoices', function () {
    paidPlan($this->owner, 'starter');
    ['owner' => $otherOwner, 'organization' => $other] = teamBusiness('Other Co');
    paidPlan($otherOwner, 'pro');
    $theirCustomer = $other->fresh()->billing_customer_id;
    $this->stripe->cards[$theirCustomer] = ['brand' => 'amex', 'last4' => '0005', 'exp_month' => 1, 'exp_year' => 2031];
    $this->stripe->invoices[$theirCustomer] = [['id' => 'in_theirs', 'number' => 'THEIRS-1', 'created' => time(), 'total' => 7900, 'currency' => 'usd', 'status' => 'paid']];

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee('Starter')->assertDontSee('Amex')->assertDontSee('0005');
    Livewire::actingAs($this->owner->fresh())->test(BillingHistory::class)->assertDontSee('THEIRS-1');
    Livewire::actingAs($otherOwner->fresh())->test(BillingHistory::class)->assertSee('THEIRS-1');

    // Acting on a plan only ever touches the signed-in organization's subscription.
    Livewire::actingAs($this->owner->fresh())->test(BillingPlans::class)->call('choosePlan', 'pro');
    expect(Subscription::where('organization_id', $this->organization->id)->sole()->plan)->toBe('pro')
        ->and(Subscription::where('organization_id', $other->id)->sole()->plan)->toBe('pro');
    $this->stripe->subscriptions[Subscription::where('organization_id', $other->id)->sole()->provider_subscription_id]['status'] = 'active';
});

// ─── Upgrade prompts ─────────────────────────────────────────────────────────────────────

test('when the customer limit is reached the form says so with an upgrade link for the owner only', function () {
    tightFreePlan(['customers' => 1]);
    $this->organization->forceFill(['trial_ends_at' => null])->save();

    $fill = fn ($page) => $page->set('first_name', 'Over')->set('last_name', 'Limit')->set('email', 'over@example.com')->call('save');

    $owner = $fill(Livewire::actingAs($this->owner->fresh())->test(CustomerForm::class))->assertHasErrors('email')->assertSee('Your Free plan allows up to 1 customers')->assertSee('Upgrade Plan');
    expect($owner->html())->toContain(route('settings.billing.plans'));

    $fill(Livewire::actingAs($this->manager->fresh())->test(CustomerForm::class))->assertHasErrors('email')->assertSee('Your Free plan allows up to 1 customers')->assertDontSee('Upgrade Plan');
});

test('ordinary pages carry no upgrade prompts while limits have room', function () {
    paidPlan($this->owner, 'pro');

    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('Upgrade Plan');
    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)->assertDontSee('Upgrade Plan');
    Livewire::actingAs($this->owner->fresh())->test(BillingUsage::class)->assertDontSee('Upgrade Plan');
});

// ─── Mobile ──────────────────────────────────────────────────────────────────────────────

test('billing pages are built for narrow screens: scrolling tabs, stacked buttons and cards', function () {
    $html = $this->actingAs($this->owner)->get(route('settings.billing'))->assertOk()->getContent();

    expect($html)->toContain('overflow-x-auto')->toContain('flex-col gap-2 sm:flex-row');
    $plans = $this->actingAs($this->owner)->get(route('settings.billing.plans'))->getContent();
    expect($plans)->toContain('lg:grid-cols-3');
});

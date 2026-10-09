<?php

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Contracts\Billing\BillingProviderInterface;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\MessageDirection;
use App\Enums\Team\MemberStatus;
use App\Exceptions\Billing\BillingException;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\BillingUsage;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingProviderManager;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\Providers\ManualBillingProvider;
use App\Services\Billing\UsageService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'organization' => $this->organization] = teamBusiness();
    $this->billing = app(BillingService::class);
    $this->entitlements = app(EntitlementService::class);
});

/**
 * A stored subscription in a given state (as a provider sync would record it).
 */
function subscriptionFor(Organization $organization, string $plan, SubscriptionStatus $status, array $attributes = []): Subscription
{
    return app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription(
        id: $attributes['id'] ?? 'manual_sub_'.uniqid(),
        planKey: $plan,
        status: $status,
        trialEndsAt: $attributes['trial_ends_at'] ?? null,
        currentPeriodStart: CarbonImmutable::now(),
        currentPeriodEnd: $attributes['current_period_end'] ?? CarbonImmutable::now()->addMonth(),
        cancelAtPeriodEnd: $attributes['cancel_at_period_end'] ?? false,
    ));
}

// Plan configuration

test('plans are defined once with prices, limits, features and provider price IDs', function () {
    $plans = app(PlanCatalog::class)->all();

    expect(array_keys($plans))->toBe(['free', 'starter', 'pro'])
        ->and($plans['free']->priceCents)->toBe(0)
        ->and($plans['starter']->priceCents)->toBe(2900)
        ->and($plans['pro']->priceCents)->toBe(7900)
        ->and($plans['pro']->priceLabel())->toBe('$79.00 / month')
        ->and(app(PlanCatalog::class)->default()->key)->toBe('free');

    $expected = [
        'free' => [100, 3, 1, 500, 50],
        'starter' => [500, 15, 3, 2500, 250],
        'pro' => [2000, 50, 10, 10000, 1000],
    ];

    foreach ($expected as $key => $limits) {
        expect(array_map(fn (LimitKey $l) => $plans[$key]->limit($l), [LimitKey::Customers, LimitKey::Automations, LimitKey::TeamMembers, LimitKey::OutboundEmails, LimitKey::Estimates]))->toBe($limits, $key);
    }

    expect($plans['pro']->hasFeature('priority_support'))->toBeTrue()
        ->and($plans['free']->hasFeature('priority_support'))->toBeFalse()
        ->and($plans['free']->providerPriceId)->toBeNull();
});

test('invalid plan configuration fails loudly', function () {
    $valid = config('billing');

    $broken = [
        'missing limit' => function ($c) {
            unset($c['plans']['pro']['limits']['estimates']);

            return $c;
        },
        'negative price' => fn ($c) => array_replace_recursive($c, ['plans' => ['pro' => ['price_cents' => -1]]]),
        'bad interval' => fn ($c) => array_replace_recursive($c, ['plans' => ['pro' => ['interval' => 'fortnight']]]),
        'bad limit' => fn ($c) => array_replace_recursive($c, ['plans' => ['pro' => ['limits' => ['customers' => 'lots']]]]),
        'unknown default' => fn ($c) => array_replace($c, ['default_plan' => 'enterprise']),
    ];

    foreach ($broken as $case => $mutate) {
        $config = $mutate($valid);

        expect(fn () => new PlanCatalog($config))->toThrow(BillingException::class, null, $case);
    }

    expect(fn () => app(PlanCatalog::class)->get('enterprise'))->toThrow(BillingException::class, 'Unknown plan [enterprise].');
});

test('plan values live only in the billing configuration', function () {
    // No prices or plan limits are hard-coded elsewhere in the application.
    $hits = collect(File::allFiles(app_path()))
        ->reject(fn ($f) => str_contains($f->getPathname(), '/Billing/') || str_contains($f->getPathname(), 'Enums'))
        ->filter(fn ($f) => preg_match("/\\b(2900|7900)\\b|'(starter|pro)'/", $f->getContents()))
        ->map(fn ($f) => $f->getRelativePathname())->values()->all();

    expect($hits)->toBe([]);
});

// Subscription status

test('subscription statuses decide access', function () {
    expect(array_map(fn ($s) => $s->value, SubscriptionStatus::cases()))->toBe(['trialing', 'active', 'past_due', 'paused', 'cancelled', 'incomplete', 'unpaid', 'expired']);

    foreach (SubscriptionStatus::cases() as $status) {
        expect($status->grantsAccess())->toBe(in_array($status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue], true), $status->value)
            ->and($status->isTerminal())->toBe(in_array($status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true), $status->value);
    }

    expect(SubscriptionStatus::PastDue->label())->toBe('Past due');
});

// Subscription creation

test('the owner can subscribe, with an optional trial, through the provider abstraction', function () {
    $subscription = $this->billing->subscribe($this->owner, 'starter', trialDays: 14);

    expect($subscription->organization_id)->toBe($this->organization->id)
        ->and($subscription->provider)->toBe('manual')
        ->and($subscription->provider_subscription_id)->toStartWith('manual_sub_')
        ->and($subscription->plan)->toBe('starter')
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->onTrial())->toBeTrue()
        ->and($subscription->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($this->organization->fresh()->billing_customer_id)->toStartWith('manual_cus_')
        ->and($this->organization->fresh()->currentSubscription->id)->toBe($subscription->id)
        ->and(OrganizationActivity::where('action', 'subscription_started')->sole()->data)->toMatchArray(['plan' => 'starter']);

    // One current subscription per business.
    expect(fn () => $this->billing->subscribe($this->owner, 'pro'))->toThrow(BillingException::class, 'already has a subscription')
        ->and(fn () => DB::transaction(fn () => subscriptionFor($this->organization, 'pro', SubscriptionStatus::Active)))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $this->billing->subscribe($this->owner->fresh(), 'free'))->toThrow(BillingException::class);
});

test('plan changes, cancellation and resuming go through the billing service', function () {
    $this->billing->subscribe($this->owner, 'starter');

    $changed = $this->billing->changePlan($this->owner, 'pro');
    expect($changed->plan)->toBe('pro')->and($this->entitlements->plan($this->organization)->key)->toBe('pro');

    $scheduled = $this->billing->cancel($this->owner, atPeriodEnd: true);
    expect($scheduled->status)->toBe(SubscriptionStatus::Active)->and($scheduled->cancel_at_period_end)->toBeTrue()->and($scheduled->grantsAccess())->toBeTrue();

    $resumed = $this->billing->resume($this->owner);
    expect($resumed->cancel_at_period_end)->toBeFalse()->and($resumed->canceled_at)->toBeNull();

    $ended = $this->billing->cancel($this->owner, atPeriodEnd: false);
    expect($ended->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->billing->currentSubscription($this->organization))->toBeNull()
        ->and($this->entitlements->plan($this->organization)->key)->toBe('free');

    // A new subscription can start after the old one ended; history keeps both.
    $this->billing->subscribe($this->owner, 'starter');
    expect(Subscription::where('organization_id', $this->organization->id)->count())->toBe(2)
        ->and(OrganizationActivity::where('action', 'like', 'subscription_%')->count())->toBe(7); // incl. subscription_ended
});

test('the application can use another provider without changing the services', function () {
    $fake = new class extends ManualBillingProvider
    {
        public array $calls = [];

        public function name(): string
        {
            return 'fake';
        }

        public function createSubscription(string $customerId, Plan $plan, int $trialDays = 0): ProviderSubscription
        {
            $this->calls[] = "subscribe:{$plan->key}";

            return new ProviderSubscription('sub_fake_1', $plan->key, SubscriptionStatus::Incomplete);
        }
    };
    app(BillingProviderManager::class)->extend('fake', fn () => $fake);
    config(['billing.provider' => 'fake']);

    $subscription = $this->billing->subscribe($this->owner, 'pro');

    expect($fake->calls)->toBe(['subscribe:pro'])
        ->and($subscription->provider)->toBe('fake')
        ->and($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        // Incomplete (payment not done) gives no access to Pro yet.
        ->and($this->entitlements->plan($this->organization)->key)->toBe('free');

    expect($fake)->toBeInstanceOf(BillingProviderInterface::class);
});

// Entitlement resolution

test('entitlements resolve to the plan of a subscription that grants access, otherwise free', function () {
    expect($this->entitlements->plan($this->organization)->key)->toBe('free');

    $cases = [
        ['pro', SubscriptionStatus::Active, [], 'pro'],
        ['pro', SubscriptionStatus::Trialing, ['trial_ends_at' => CarbonImmutable::now()->addDays(3)], 'pro'],
        ['pro', SubscriptionStatus::Trialing, ['trial_ends_at' => CarbonImmutable::now()->subDay()], 'free'],
        ['pro', SubscriptionStatus::PastDue, [], 'pro'],
        ['pro', SubscriptionStatus::Paused, [], 'free'],
        ['pro', SubscriptionStatus::Unpaid, [], 'free'],
        ['pro', SubscriptionStatus::Incomplete, [], 'free'],
        ['pro', SubscriptionStatus::Active, ['cancel_at_period_end' => true, 'current_period_end' => CarbonImmutable::now()->addDay()], 'pro'],
        ['pro', SubscriptionStatus::Active, ['cancel_at_period_end' => true, 'current_period_end' => CarbonImmutable::now()->subMinute()], 'free'],
        ['starter', SubscriptionStatus::Cancelled, [], 'free'],
        ['starter', SubscriptionStatus::Expired, [], 'free'],
    ];

    foreach ($cases as $i => [$plan, $status, $attributes, $expected]) {
        Subscription::query()->delete();
        subscriptionFor($this->organization, $plan, $status, $attributes);

        expect($this->entitlements->plan($this->organization->fresh())->key)->toBe($expected, "case {$i}: {$plan} {$status->value}");
    }

    // A plan removed from the configuration falls back to free instead of breaking.
    Subscription::query()->delete();
    $legacy = subscriptionFor($this->organization, 'pro', SubscriptionStatus::Active);
    DB::table('subscriptions')->where('id', $legacy->id)->update(['plan' => 'legacy_gold']);
    expect($this->entitlements->plan($this->organization)->key)->toBe('free')
        ->and($this->entitlements->hasFeature($this->organization, 'priority_support'))->toBeFalse();
});

// Plan limits and usage

test('limits are checked against real usage', function () {
    // Free: 100 customers, 3 automations, 1 team member, 500 emails, 50 estimates.
    Customer::factory()->count(99)->for($this->organization)->create();
    expect(app(UsageService::class)->usage($this->organization, LimitKey::Customers))->toBe(100) // + the helper's customer
        ->and($this->entitlements->allows($this->organization, LimitKey::Customers))->toBeFalse()
        ->and($this->entitlements->remaining($this->organization, LimitKey::Customers))->toBe(0);

    // Team: owner, manager, staff = 3 seats (removed people don't count) — over the free limit of 1.
    User::factory()->staff()->for($this->organization)->create(['status' => MemberStatus::Removed]);
    $summary = $this->entitlements->summary($this->organization);
    expect($summary['team_members'])->toMatchArray(['used' => 3, 'limit' => 1, 'remaining' => 0, 'over' => true]);

    // Only running automations use a slot: drafts, paused and archived ones don't.
    Automation::factory()->count(2)->create(['organization_id' => $this->organization->id, 'status' => AutomationStatus::Active]);
    Automation::factory()->create(['organization_id' => $this->organization->id, 'status' => AutomationStatus::Draft]);
    Automation::factory()->create(['organization_id' => $this->organization->id, 'status' => AutomationStatus::Archived]);
    expect($this->entitlements->allows($this->organization, LimitKey::Automations))->toBeTrue()
        ->and($this->entitlements->allows($this->organization, LimitKey::Automations, 2))->toBeFalse();

    // Upgrading raises every limit at once.
    subscriptionFor($this->organization, 'pro', SubscriptionStatus::Active);
    expect($this->entitlements->allows($this->organization, LimitKey::Customers, 1900))->toBeTrue()
        ->and($this->entitlements->allows($this->organization, LimitKey::Customers, 1901))->toBeFalse()
        ->and($this->entitlements->limit($this->organization, LimitKey::TeamMembers))->toBe(10);
});

test('monthly limits count this calendar month in the business timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30', 'America/Chicago'));
    $connection = EmailConnection::where('organization_id', $this->organization->id)->sole();
    $email = fn ($at) => Message::factory()->create(['email_connection_id' => $connection->id, 'direction' => MessageDirection::Outbound, 'created_at' => $at->utc()]);

    $email(CarbonImmutable::parse('2026-10-01 00:30', 'America/Chicago'));  // October (Chicago) — counts
    $email(CarbonImmutable::parse('2026-10-31 23:00', 'America/Chicago'));  // October 31, 11 PM Chicago = November UTC — still October here
    $email(CarbonImmutable::parse('2026-09-30 23:30', 'America/Chicago'));  // September — doesn't count
    Message::factory()->create(['email_connection_id' => $connection->id, 'direction' => MessageDirection::Inbound]); // inbound never counts

    expect(app(UsageService::class)->usage($this->organization, LimitKey::OutboundEmails))->toBe(2);

    // New estimates count; revisions of an estimate don't.
    $customer = Customer::where('organization_id', $this->organization->id)->first();
    $original = Estimate::factory()->create(['customer_id' => $customer->id, 'revision' => 1]);
    Estimate::factory()->create(['customer_id' => $customer->id, 'revision' => 2, 'estimate_number' => $original->estimate_number, 'revision_of_id' => $original->id]);
    expect(app(UsageService::class)->usage($this->organization, LimitKey::Estimates))->toBe(1);

    // Next month starts from zero.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'America/Chicago'));
    expect(app(UsageService::class)->usage($this->organization, LimitKey::OutboundEmails))->toBe(0)
        ->and(app(UsageService::class)->usage($this->organization, LimitKey::Estimates))->toBe(0);
});

// Authorization

test('only the owner can manage billing', function () {
    $this->actingAs($this->owner)->get('/settings/billing')->assertOk()->assertSee('Billing')->assertSee('Free');

    foreach ([$this->manager, $this->staff] as $user) {
        $this->actingAs($user)->get('/settings/billing')->assertForbidden();
        expect(fn () => $this->billing->subscribe($user, 'pro'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->billing->cancel($user))->toThrow(AuthorizationException::class)
            ->and($user->can('manage-billing'))->toBeFalse();
    }

    // Guests can't either.
    Auth::logout();
    $this->get('/settings/billing')->assertRedirect(route('login'));
    expect(Subscription::count())->toBe(0);

    $subscription = $this->billing->subscribe($this->owner, 'starter');
    expect($this->owner->can('view', $subscription))->toBeTrue()
        ->and($this->manager->can('view', $subscription))->toBeFalse();
});

// Organization isolation

test('billing never crosses organizations', function () {
    ['owner' => $otherOwner, 'organization' => $other] = teamBusiness('Other Co');
    $theirs = $this->billing->subscribe($otherOwner, 'pro');

    // Our plan and usage are our own.
    expect($this->entitlements->plan($this->organization)->key)->toBe('free')
        ->and($this->billing->currentSubscription($this->organization))->toBeNull()
        ->and($this->owner->can('view', $theirs))->toBeFalse();

    // Our owner's actions only touch our organization.
    expect(fn () => $this->billing->cancel($this->owner))->toThrow(BillingException::class, 'no subscription');
    expect($theirs->fresh()->status)->toBe(SubscriptionStatus::Active);

    // A provider update can't move their subscription into our organization.
    expect(fn () => $this->billing->sync($this->organization, 'manual', new ProviderSubscription($theirs->provider_subscription_id, 'pro', SubscriptionStatus::Active)))
        ->toThrow(BillingException::class, 'belongs to another organization');
    expect($theirs->fresh()->organization_id)->toBe($other->id);

    // The billing page shows only our plan.
    Livewire::actingAs($this->owner)->test(BillingOverview::class)->assertSee('No subscription')->assertDontSee('Cancels at period end');
});

test('the billing page shows the plan, status and usage', function () {
    $this->billing->subscribe($this->owner, 'starter', trialDays: 14);

    Livewire::actingAs($this->owner->fresh())->test(BillingOverview::class)
        ->assertSeeInOrder(['Current plan', 'Starter', '$29.00 / month', 'Trialing', 'Billing period', 'Trial ends', 'Next billing date'])
        ->assertSee('Manage billing')->assertSee('Change plan')->assertSee('Cancel subscription');

    Livewire::actingAs($this->owner->fresh())->test(BillingUsage::class)
        ->assertSeeInOrder(['Customers', '1 / 500', 'Team members', '3 / 3']);
});

test('with the manual provider the same checkout flow completes locally without payment', function () {
    $page = Livewire::actingAs($this->owner)->test(BillingOverview::class)->call('choosePlan', 'starter');
    $redirect = $page->effects['redirect'];
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($redirect)->toStartWith(route('settings.billing'))->and($query['session_id'])->toStartWith('manual_cs_');

    Livewire::withQueryParams($query)->actingAs($this->owner->fresh())->test(BillingOverview::class)->assertSee('Your Starter trial has started.');

    $subscription = Subscription::sole();
    expect($subscription->provider)->toBe('manual')->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($this->entitlements->plan($this->organization)->key)->toBe('starter');

    // Downgrade to Free waits for the period end; the scheduled sync applies it when the time comes.
    $this->billing->changePlan($this->owner->fresh(), 'free');
    $this->travelTo($subscription->fresh()->current_period_end->addMinute());
    $this->artisan('billing:sync-subscriptions')->assertSuccessful();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)->and($this->entitlements->plan($this->organization)->key)->toBe('free');
});

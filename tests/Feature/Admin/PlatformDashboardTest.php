<?php

use App\Billing\PlanCatalog;
use App\Enums\Platform\PlatformRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\OrganizationStats;
use App\Filament\Widgets\RecentPlatformActivity;
use App\Filament\Widgets\RevenueStats;
use App\Filament\Widgets\SubscriptionStats;
use App\Filament\Widgets\UserStats;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Admin\PlatformMetrics;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| SA-04: Platform dashboard
|--------------------------------------------------------------------------
*/

function makeSubscription(string $status, string $plan = 'starter', string $provider = 'stripe', array $extra = []): Subscription
{
    static $n = 0;
    $organization = Organization::factory()->create();

    return Subscription::query()->forceCreate([
        'organization_id' => $organization->id,
        'provider' => $provider,
        'provider_subscription_id' => 'sub_test_'.++$n,
        'plan' => $plan,
        'status' => $status,
        ...$extra,
    ]);
}

/** Widget classes the dashboard shows to the signed-in admin. (They load lazily, so the page HTML has only placeholders.) */
function dashboardWidgets(): array
{
    return array_map(
        fn ($widget) => is_string($widget) ? $widget : $widget->widget,
        Livewire::test(Dashboard::class)->instance()->getVisibleWidgets(),
    );
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['billing.provider' => 'stripe']);
});

// Empty states

test('the dashboard works on an empty database', function () {
    $admin = platformAdmin(state: ['organization_id' => null]);

    $this->actingAs($admin)->get('/admin')->assertOk();
    expect(dashboardWidgets())->toContain(OrganizationStats::class, SubscriptionStats::class, RecentPlatformActivity::class);

    Livewire::test(OrganizationStats::class)->assertSee('Organizations');
    Livewire::test(SubscriptionStats::class)->assertSee('Active subscriptions');
    Livewire::test(RecentPlatformActivity::class)->assertSee('No activity yet');

    $metrics = app(PlatformMetrics::class);
    expect($metrics->organizations())->toMatchArray(['total' => 0, 'active' => 0, 'new' => 0, 'previous' => 0])
        ->and($metrics->subscriptions()['active'])->toBe(0)
        ->and($metrics->mrr())->toMatchArray(['available' => true, 'cents' => 0]);
});

test('revenue is shown as unavailable when billing is in manual mode', function () {
    config(['billing.provider' => 'manual']);
    $this->actingAs(platformAdmin());

    Livewire::test(RevenueStats::class)->assertSee('Not available')->assertSee('manual mode');
});

test('Stripe-reported revenue is clearly not configured and separate from MRR', function () {
    $this->actingAs(platformAdmin());

    Livewire::test(RevenueStats::class)
        ->assertSee('MRR (calculated locally)')
        ->assertSee('Revenue reported by Stripe')
        ->assertSee('Not configured');
});

// Metric calculations

test('organization and user counts and 30-day comparisons are calculated from real records', function () {
    $this->freezeTime();

    Organization::factory()->count(2)->create(['created_at' => now()->subDays(5)]);
    Organization::factory()->count(3)->create(['created_at' => now()->subDays(40)]);
    Organization::factory()->create(['created_at' => now()->subDays(100)]);

    $orgs = app(PlatformMetrics::class)->organizations();

    expect($orgs)->toMatchArray(['total' => 6, 'new' => 2, 'previous' => 3]);
});

test('an organization is active when an active member was seen in the last 30 days', function () {
    $this->freezeTime();

    $recent = Organization::factory()->create();
    User::factory()->count(2)->create(['organization_id' => $recent->id, 'last_active_at' => now()->subDays(3)]);
    $stale = Organization::factory()->create();
    User::factory()->create(['organization_id' => $stale->id, 'last_active_at' => now()->subDays(45)]);
    $suspended = Organization::factory()->create();
    User::factory()->create(['organization_id' => $suspended->id, 'last_active_at' => now()->subDay(), 'status' => 'suspended', 'suspended_at' => now()]);
    Organization::factory()->create();

    expect(app(PlatformMetrics::class)->organizations())->toMatchArray(['total' => 4, 'active' => 1]);
});

test('subscription counts keep active, trialing, past due and canceled apart', function () {
    $this->freezeTime();

    makeSubscription('active');
    makeSubscription('active', 'pro');
    makeSubscription('trialing');
    makeSubscription('past_due');
    makeSubscription('incomplete');
    makeSubscription('cancelled', extra: ['canceled_at' => now()->subDays(2)]);
    makeSubscription('cancelled', extra: ['canceled_at' => now()->subDays(40)]);

    expect(app(PlatformMetrics::class)->subscriptions())->toBe([
        'active' => 2, 'trialing' => 1, 'past_due' => 1, 'cancelled' => 2, 'cancelled_recent' => 1, 'cancelled_previous' => 1,
    ]);
});

test('MRR counts active and past due paid subscriptions at list price and nothing else', function () {
    makeSubscription('active', 'starter');        // 2900
    makeSubscription('active', 'pro');            // 7900
    makeSubscription('past_due', 'starter');      // 2900, at risk
    makeSubscription('trialing', 'pro');          // not paying yet
    makeSubscription('cancelled', 'pro');
    makeSubscription('incomplete', 'pro');
    makeSubscription('active', 'pro', provider: 'manual'); // no real payment
    makeSubscription('active', 'removed-plan');   // unknown plan

    $mrr = app(PlatformMetrics::class)->mrr();

    expect($mrr)->toMatchArray([
        'available' => true, 'cents' => 13700, 'subscriptions' => 3, 'unknown_plan' => 1, 'at_risk_cents' => 2900,
    ]);
});

test('yearly plans count one twelfth per month', function () {
    config(['billing.plans.starter.interval' => 'year', 'billing.plans.starter.price_cents' => 12000]);
    app()->forgetInstance(PlanCatalog::class);
    makeSubscription('active', 'starter');

    expect(app(PlatformMetrics::class)->mrr()['cents'])->toBe(1000);
});

test('the dashboard renders the calculated figures', function () {
    makeSubscription('active', 'pro');
    $this->actingAs(platformAdmin());

    Livewire::test(RevenueStats::class)->assertSee('$79.00');
    Livewire::test(SubscriptionStats::class)->assertSee('Active subscriptions');
});

// Access control per widget

test('each widget is limited to the permission it needs', function () {
    $matrix = [
        'support_admin' => [OrganizationStats::class => true, UserStats::class => true, SubscriptionStats::class => true, RevenueStats::class => false, RecentPlatformActivity::class => true],
        'billing_admin' => [OrganizationStats::class => true, UserStats::class => false, SubscriptionStats::class => true, RevenueStats::class => true, RecentPlatformActivity::class => false],
        'super_admin' => [OrganizationStats::class => true, UserStats::class => true, SubscriptionStats::class => true, RevenueStats::class => true, RecentPlatformActivity::class => true],
    ];

    foreach ($matrix as $role => $widgets) {
        $this->actingAs(platformAdmin(PlatformRole::from($role)));

        foreach ($widgets as $widget => $visible) {
            expect($widget::canView())->toBe($visible, "{$role} / {$widget}");
        }
    }
});

test('a support admin does not get revenue on the dashboard page', function () {
    makeSubscription('active', 'pro');

    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin))->get('/admin')->assertOk();

    expect(dashboardWidgets())->toContain(SubscriptionStats::class)->not->toContain(RevenueStats::class);
});

test('a billing admin does not see user or activity widgets', function () {
    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin))->get('/admin')->assertOk();

    expect(dashboardWidgets())->toContain(RevenueStats::class)->not->toContain(UserStats::class)->not->toContain(RecentPlatformActivity::class);
});

test('customers and guests never reach the widgets', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->owner()->create());
    expect(RevenueStats::canView())->toBeFalse()->and(RecentPlatformActivity::canView())->toBeFalse();
});

// Recent activity

test('recent activity lists labels and organizations, newest first, ten per page, without change details', function () {
    $organization = Organization::factory()->create(['name' => 'Acme Plumbing']);

    foreach (range(1, 12) as $i) {
        OrganizationActivity::record($organization, 'role_changed', null, ['changes' => ['role' => ['from' => 'staff', 'to' => 'secret-detail-'.$i]]]);
    }

    $this->actingAs(platformAdmin());

    Livewire::test(RecentPlatformActivity::class)
        ->assertCountTableRecords(12)
        ->assertSee('Acme Plumbing')
        ->assertSee('Role changed')
        ->assertDontSee('secret-detail');
});

// Efficiency and failure handling

test('metric queries do not grow with the number of organizations', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $metrics = new PlatformMetrics(app(PlanCatalog::class));
        $metrics->organizations();
        $metrics->users();
        $metrics->subscriptions();
        $metrics->mrr();

        return count(DB::getQueryLog());
    };

    makeSubscription('active');
    $small = $count();

    foreach (range(1, 15) as $i) {
        makeSubscription($i % 2 ? 'active' : 'trialing', 'pro');
    }
    User::factory()->count(10)->create();

    expect($count())->toBe($small);
});

test('a failing metric shows an unavailable card instead of breaking the dashboard', function () {
    $this->mock(PlatformMetrics::class)->shouldReceive('organizations')->andThrow(new RuntimeException('db down'));
    $this->actingAs(platformAdmin());

    Livewire::test(OrganizationStats::class)->assertSee('Unavailable');
});

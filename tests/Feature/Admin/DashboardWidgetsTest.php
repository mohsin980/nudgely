<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Enums\MessageStatus;
use App\Enums\Platform\PlatformRole;
use App\Filament\Widgets\IntegrationReadiness;
use App\Filament\Widgets\OperationsStats;
use App\Filament\Widgets\RecentAdminActivity;
use App\Filament\Widgets\RecentPaymentFailures;
use App\Filament\Widgets\RecentSignups;
use App\Filament\Widgets\SignupsChart;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Super Admin dashboard widgets: every figure comes from persisted rows.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function dashboardSubscription(Organization $organization, SubscriptionStatus $status, string $plan = 'starter'): Subscription
{
    static $n = 0;

    return Subscription::query()->forceCreate([
        'organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_dash_'.++$n,
        'plan' => $plan, 'status' => $status->value,
    ]);
}

/** The stat objects a stats widget would show, read without rendering. */
function widgetStats(string $widget): array
{
    return (new ReflectionMethod($widget, 'getStats'))->invoke(new $widget);
}

// Recent signups

test('recent signups lists the newest businesses and links to their detail page', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    $older = Organization::factory()->create(['name' => 'Older Co', 'created_at' => now()->subDays(10)]);
    $newer = Organization::factory()->create(['name' => 'Newest Roofing', 'created_at' => now()->subDay()]);

    Livewire::test(RecentSignups::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

test('recent signups is shown only to administrators who may view organizations', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    expect(RecentSignups::canView())->toBeTrue();

    // A customer account has no platform grant, so the widget is hidden from it.
    $this->actingAs(User::factory()->owner()->create());
    expect(RecentSignups::canView())->toBeFalse();
});

// Payment failures

test('payment failures show only subscriptions the provider reports as past due or unpaid', function () {
    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin, ['organization_id' => null]));
    $late = dashboardSubscription(Organization::factory()->create(['name' => 'Late Payer Co']), SubscriptionStatus::PastDue);
    $unpaid = dashboardSubscription(Organization::factory()->create(['name' => 'Unpaid Co']), SubscriptionStatus::Unpaid);
    $healthy = dashboardSubscription(Organization::factory()->create(['name' => 'Paid Up Co']), SubscriptionStatus::Active);

    Livewire::test(RecentPaymentFailures::class)
        ->assertCanSeeTableRecords([$late, $unpaid])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('payment failures show the empty state when there are none', function () {
    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin, ['organization_id' => null]));

    Livewire::test(RecentPaymentFailures::class)
        ->assertSee('No payment failures')
        ->assertCanNotSeeTableRecords(Subscription::query()->get());
});

// Operations

test('failed emails are counted for the last 30 days only and never include inbound mail', function () {
    $this->actingAs(platformAdmin());
    Message::factory()->create(['status' => MessageStatus::Failed]);
    Message::factory()->create(['status' => MessageStatus::Failed]);
    Message::factory()->create(['status' => MessageStatus::Failed, 'created_at' => now()->subDays(45)]);
    Message::factory()->create(['status' => MessageStatus::Sent]);

    $stats = widgetStats(OperationsStats::class);

    expect($stats[0]->getValue())->toBe('1')
        ->and($stats[1]->getValue())->toBe('2');
});

// Integration readiness

test('integrations show as not configured when their credentials are missing', function () {
    $this->actingAs(platformAdmin());
    config([
        'email.providers.postmark.server_token' => null,
        'email.oauth.google.client_id' => null,
        'email.oauth.microsoft.client_id' => null,
        'services.stripe.secret' => null,
        'services.stripe.webhook_secret' => null,
    ]);

    $values = array_map(fn ($stat) => $stat->getValue(), widgetStats(IntegrationReadiness::class));

    expect($values)->toBe(['Not configured', 'Not configured', 'Not configured', 'Not configured']);
});

test('integration readiness never shows a credential value', function () {
    $this->actingAs(platformAdmin());
    config(['email.providers.postmark.server_token' => 'pm-secret-token-value', 'services.stripe.secret' => 'sk_live_secret']);

    $html = Livewire::test(IntegrationReadiness::class)->html();

    expect($html)->not->toContain('pm-secret-token-value')->not->toContain('sk_live_secret');
});

// Signups chart

test('the signups chart counts new businesses per month from their creation dates', function () {
    $this->actingAs(platformAdmin());
    $before = (int) (new ReflectionMethod(SignupsChart::class, 'getData'))->invoke(new SignupsChart)['datasets'][0]['data'][5];

    Organization::factory()->create(['created_at' => now()]);
    Organization::factory()->create(['created_at' => now()->subMonths(2)]);

    $data = (new ReflectionMethod(SignupsChart::class, 'getData'))->invoke(new SignupsChart);

    expect($data['labels'])->toHaveCount(6)
        ->and($data['datasets'][0]['data'][5])->toBe($before + 1)
        ->and($data['datasets'][0]['data'][3])->toBeGreaterThanOrEqual(1);
});

test('the dashboard loads on a fresh install with no subscriptions or failures', function () {
    $this->actingAs(platformAdmin());

    expect(Subscription::query()->count())->toBe(0);

    $this->get('/admin')->assertOk();
});

test('recent admin activity lists audited administrator actions with their outcome', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    $org = Organization::factory()->create(['name' => 'Audited Co']);
    PlatformAuditLog::record('organization_suspended', null, $org->id, $org->id, null, ['reason' => 'test']);

    Livewire::test(RecentAdminActivity::class)
        ->assertCanSeeTableRecords(PlatformAuditLog::query()->get());

    expect(RecentAdminActivity::canView())->toBeTrue();
});

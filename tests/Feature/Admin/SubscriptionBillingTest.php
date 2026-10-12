<?php

use App\Enums\Billing\DataFreshness;
use App\Enums\Platform\PlatformRole;
use App\Exceptions\Platform\SubscriptionRefreshException;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Platform\SubscriptionRefresh;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

/*
|--------------------------------------------------------------------------
| SA-08: Subscription and billing management
|--------------------------------------------------------------------------
*/

/**
 * A business that paid on Stripe, with the subscription recorded through the real webhook path.
 *
 * @return array{owner: User, organization: Organization, subscription: Subscription, remote: string, customer: string}
 */
function paidBusiness(string $name = 'Dallas HVAC', string $plan = 'starter'): array
{
    ['owner' => $owner, 'organization' => $organization] = teamBusiness($name);
    $session = app(BillingService::class)->startCheckout($owner, $plan, 'https://a', 'https://b');
    $remote = test()->stripe->completeCheckout($session->id);
    $customer = $organization->fresh()->billing_customer_id;

    webhook(stripeEvent('customer.subscription.created', ['id' => $remote['id'], 'customer' => $customer]))->assertOk();

    return ['owner' => $owner, 'organization' => $organization, 'subscription' => Subscription::query()->where('organization_id', $organization->id)->sole(), 'remote' => $remote['id'], 'customer' => $customer];
}

function billingAdmin(PlatformRole $role = PlatformRole::SuperAdmin): User
{
    return platformAdmin($role, ['organization_id' => null]);
}

beforeEach(function () {
    $this->withoutVite();
    $this->stripe = FakeStripe::install();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Listing, pagination, filters

test('administrators with view_subscriptions see subscriptions with plan, status and provider data state', function () {
    $paid = paidBusiness();

    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin, PlatformRole::SuperAdmin] as $role) {
        $this->actingAs(billingAdmin($role));

        Livewire::test(ListSubscriptions::class)
            ->assertCanSeeTableRecords([$paid['subscription']])
            ->assertSee('Dallas HVAC')->assertSee('Starter')->assertSee('Active')->assertSee('Confirmed');
    }
});

test('the list is paginated', function () {
    $this->actingAs(billingAdmin());
    foreach (range(1, 30) as $i) {
        $organization = Organization::factory()->create();
        Subscription::query()->forceCreate(['organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => "sub_p_{$i}", 'plan' => 'pro', 'status' => 'active']);
    }

    $list = Livewire::test(ListSubscriptions::class);
    expect($list->instance()->getTableRecords())->toHaveCount(25);
    $list->call('gotoPage', 2);
    expect($list->instance()->getTableRecords())->toHaveCount(5);
});

test('filters narrow by plan, status, billing interval, organization and data freshness', function () {
    $this->actingAs(billingAdmin());
    $make = function (string $plan, string $status, ?string $synced = 'now') {
        $organization = Organization::factory()->create();
        static $n = 0;

        return Subscription::query()->forceCreate([
            'organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_f_'.++$n,
            'plan' => $plan, 'status' => $status, 'last_synced_at' => $synced === null ? null : ($synced === 'now' ? now() : now()->subDay()),
        ]);
    };
    $starter = $make('starter', 'active');
    $proPastDue = $make('pro', 'past_due', 'old');
    $proNever = $make('pro', 'canceled' === 'x' ? 'active' : 'cancelled', null);

    Livewire::test(ListSubscriptions::class)
        ->filterTable('plan', 'starter')->assertCanSeeTableRecords([$starter])->assertCanNotSeeTableRecords([$proPastDue, $proNever])
        ->resetTableFilters()
        ->filterTable('status', 'past_due')->assertCanSeeTableRecords([$proPastDue])->assertCanNotSeeTableRecords([$starter])
        ->resetTableFilters()
        ->filterTable('interval', 'month')->assertCanSeeTableRecords([$starter, $proPastDue])
        ->resetTableFilters()
        ->filterTable('interval', 'year')->assertCanNotSeeTableRecords([$starter, $proPastDue])
        ->resetTableFilters()
        ->filterTable('organization_id', $starter->organization_id)->assertCanSeeTableRecords([$starter])->assertCanNotSeeTableRecords([$proPastDue])
        ->resetTableFilters()
        ->filterTable('freshness', 'stale')->assertCanSeeTableRecords([$proPastDue])->assertCanNotSeeTableRecords([$starter, $proNever])
        ->resetTableFilters()
        ->filterTable('freshness', 'unconfirmed')->assertCanSeeTableRecords([$proNever])->assertCanNotSeeTableRecords([$starter])
        ->resetTableFilters()
        ->filterTable('freshness', 'confirmed')->assertCanSeeTableRecords([$starter])->assertCanNotSeeTableRecords([$proPastDue, $proNever]);
});

// Data consistency with the provider and webhooks

test('the stored state follows Stripe through webhooks: past due, canceled and active are shown correctly', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin());

    $this->stripe->subscriptions[$paid['remote']]['status'] = 'past_due';
    webhook(stripeEvent('invoice.payment_failed', ['customer' => $paid['customer'], 'subscription' => $paid['remote']]))->assertOk();
    expect($paid['subscription']->fresh()->status->value)->toBe('past_due');
    Livewire::test(ListSubscriptions::class)->assertSee('Past due');

    $this->stripe->subscriptions[$paid['remote']]['status'] = 'active';
    webhook(stripeEvent('invoice.paid', ['customer' => $paid['customer'], 'subscription' => $paid['remote']]))->assertOk();
    expect($paid['subscription']->fresh()->status->value)->toBe('active');

    $this->stripe->advancePastPeriodEnd($paid['remote']);
    $this->stripe->subscriptions[$paid['remote']]['status'] = 'canceled';
    webhook(stripeEvent('customer.subscription.deleted', ['id' => $paid['remote'], 'customer' => $paid['customer']]))->assertOk();
    expect($paid['subscription']->fresh()->status->value)->toBe('cancelled');
    Livewire::test(ListSubscriptions::class)->assertSee('Cancelled');
});

test('incomplete payments are shown as pending, not as active', function () {
    $paid = paidBusiness();
    $this->stripe->subscriptions[$paid['remote']]['status'] = 'incomplete';
    webhook(stripeEvent('customer.subscription.updated', ['id' => $paid['remote'], 'customer' => $paid['customer']]))->assertOk();

    expect($paid['subscription']->fresh()->status->value)->toBe('incomplete');
    $this->actingAs(billingAdmin());
    Livewire::test(ListSubscriptions::class)->assertSee('Incomplete');
});

test('a duplicate webhook delivery creates one event record and one subscription', function () {
    $paid = paidBusiness();
    $event = stripeEvent('invoice.paid', ['customer' => $paid['customer'], 'subscription' => $paid['remote']], 'evt_dup_1');

    webhook($event)->assertOk();
    webhook($event)->assertOk();

    expect(BillingWebhookEvent::where('provider_event_id', 'evt_dup_1')->count())->toBe(1)
        ->and(Subscription::where('organization_id', $paid['organization']->id)->count())->toBe(1);

    $this->actingAs(billingAdmin());
    $list = Livewire::test(ListSubscriptions::class);
    expect($list->instance()->getTableRecords())->toHaveCount(1);
});

test('a webhook with a bad signature changes nothing', function () {
    $paid = paidBusiness();
    $before = Subscription::sole()->getAttributes();
    $this->stripe->subscriptions[$paid['remote']]['status'] = 'past_due';

    webhook(stripeEvent('invoice.payment_failed', ['customer' => $paid['customer'], 'subscription' => $paid['remote']]), secret: 'whsec_wrong')->assertStatus(400);

    expect(Subscription::sole()->getAttributes())->toBe($before);
});

test('opening the panel never marks anything paid or changes a subscription', function () {
    $paid = paidBusiness();
    $before = Subscription::sole()->fresh()->getAttributes();
    $this->actingAs(billingAdmin());

    Livewire::test(ListSubscriptions::class);
    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id]);

    expect(Subscription::sole()->getAttributes())->toBe($before)->and(PlatformAuditLog::count())->toBe(0);
});

// Freshness: missing, stale, confirmed

test('freshness distinguishes confirmed, stale, never confirmed and local records', function () {
    $mk = fn (array $state) => (new Subscription)->forceFill(['provider' => 'stripe', 'status' => 'active', ...$state]);

    expect($mk(['last_synced_at' => now()])->dataFreshness())->toBe(DataFreshness::Confirmed)
        ->and($mk(['last_synced_at' => now()->subHours(5)])->dataFreshness())->toBe(DataFreshness::Stale)
        ->and($mk(['last_synced_at' => null])->dataFreshness())->toBe(DataFreshness::Unconfirmed)
        ->and($mk(['provider' => 'manual', 'last_synced_at' => null])->dataFreshness())->toBe(DataFreshness::Local)
        ->and($mk(['status' => 'cancelled', 'last_synced_at' => now()->subDays(30)])->dataFreshness())->toBe(DataFreshness::Confirmed);
});

test('every webhook or sync records when the provider last confirmed the subscription', function () {
    $this->travelTo(now()->subHours(5));
    $paid = paidBusiness();
    expect($paid['subscription']->last_synced_at)->not->toBeNull();
    $this->travelBack();
    expect($paid['subscription']->fresh()->dataFreshness())->toBe(DataFreshness::Stale);

    webhook(stripeEvent('customer.subscription.updated', ['id' => $paid['remote'], 'customer' => $paid['customer']]))->assertOk();
    expect($paid['subscription']->fresh()->dataFreshness())->toBe(DataFreshness::Confirmed);
});

// Details, invoices, webhook activity

test('details show organization, plan, status, period, provider ID and dates', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin(PlatformRole::SupportAdmin));

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])
        ->assertSee('Dallas HVAC')->assertSee('Starter')->assertSee($paid['remote'])->assertSee('Period end')->assertSee('Last confirmed by provider');
});

test('invoices come live from Stripe, are labelled as such, and are never stored', function () {
    $paid = paidBusiness();
    $this->stripe->invoices[$paid['customer']] = [[
        'id' => 'in_123', 'number' => 'INV-0001', 'created' => 1760000000, 'total' => 2900, 'currency' => 'usd', 'status' => 'paid',
    ], [
        'id' => 'in_124', 'number' => 'INV-0002', 'created' => 1762600000, 'total' => 2900, 'currency' => 'usd', 'status' => 'open',
    ]];
    $this->actingAs(billingAdmin(PlatformRole::BillingAdmin));
    $tables = DB::select('show tables');

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])
        ->assertSee('Not loaded yet.')
        ->callAction('loadInvoices')
        ->assertSee('Loaded from Stripe')
        ->assertSee('INV-0001')->assertSee('$29.00')->assertSee('Paid')->assertSee('Open')->assertSee('in_123')->assertSee('as Stripe reports them');

    expect(DB::select('show tables'))->toEqual($tables);
});

test('when Stripe is unreachable the invoice area says so instead of showing an empty history', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin(PlatformRole::BillingAdmin));
    $this->stripe->failToConnect();
    Cache::flush();

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])
        ->callAction('loadInvoices')
        ->assertSee('Stripe could not be reached')->assertDontSee('Stripe reported no invoices');
});

test('support cannot see payments, invoices or webhook activity', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin(PlatformRole::SupportAdmin));

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])
        ->assertDontSee('Invoices reported by Stripe')->assertDontSee('Webhook activity')
        ->assertActionHidden('loadInvoices');
});

test('webhook activity lists event types and results without payloads', function () {
    $paid = paidBusiness();
    webhook(stripeEvent('invoice.paid', ['customer' => $paid['customer'], 'subscription' => $paid['remote'], 'card' => ['number' => '4242424242424242']], 'evt_secretish'))->assertOk();
    $this->actingAs(billingAdmin(PlatformRole::BillingAdmin));

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])
        ->assertSee('invoice.paid')->assertSee('Processed')->assertDontSee('4242424242424242');
});

// Access control

test('customers and guests cannot reach subscriptions', function () {
    $paid = paidBusiness();

    $this->get('/admin/subscriptions')->assertRedirect('/admin/login');
    $this->actingAs($paid['owner']);
    $this->get('/admin/subscriptions')->assertForbidden();
    $this->get("/admin/subscriptions/{$paid['subscription']->id}")->assertForbidden();
    expect(SubscriptionResource::canAccess())->toBeFalse();
});

test('there is no way to create, edit, delete, cancel or refund from the panel', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin());

    expect(SubscriptionResource::canCreate())->toBeFalse()
        ->and(SubscriptionResource::canEdit($paid['subscription']))->toBeFalse()
        ->and(SubscriptionResource::canDelete($paid['subscription']))->toBeFalse()
        ->and(array_keys(SubscriptionResource::getPages()))->toBe(['index', 'view']);

    $actions = collect(Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])->instance()->getCachedHeaderActions())->map->getName()->all();
    expect($actions)->toBe(['loadInvoices', 'refresh']);
});

// Refresh

test('only administrators with manage_subscriptions may refresh, and customers never can', function () {
    $paid = paidBusiness();
    $service = app(SubscriptionRefresh::class);

    foreach ([billingAdmin(PlatformRole::SupportAdmin), billingAdmin(PlatformRole::BillingAdmin), $paid['owner']] as $user) {
        expect(fn () => $service->refresh($paid['subscription'], $user))->toThrow(AuthorizationException::class);
    }

    expect(PlatformAuditLog::count())->toBe(0);
});

test('refresh re-reads Stripe through the billing service, records what changed and is audited', function () {
    $paid = paidBusiness();
    $admin = billingAdmin();
    $this->stripe->subscriptions[$paid['remote']]['status'] = 'past_due';
    $this->travelTo(now()->addHours(5));

    expect($paid['subscription']->fresh()->status->value)->toBe('trialing');

    $fresh = app(SubscriptionRefresh::class)->refresh($paid['subscription']->fresh(), $admin);

    expect($fresh->status->value)->toBe('past_due')->and($fresh->dataFreshness())->toBe(DataFreshness::Confirmed);
    $log = PlatformAuditLog::sole();
    expect($log->action)->toBe('subscription_refreshed')->and($log->actor_id)->toBe($admin->id)->and($log->organization_id)->toBe($paid['organization']->id)
        ->and($log->changes['changed']['status'])->toEqual(['from' => 'trialing', 'to' => 'past_due']);
});

test('refresh is rate limited and reports provider failures without changing the record', function () {
    $paid = paidBusiness();
    $admin = billingAdmin();
    $service = app(SubscriptionRefresh::class);

    $this->stripe->failWith(500);
    $before = $paid['subscription']->fresh()->getAttributes();
    expect(fn () => $service->refresh($paid['subscription']->fresh(), $admin))->toThrow(SubscriptionRefreshException::class);
    expect($paid['subscription']->fresh()->getAttributes())->toBe($before)->and(PlatformAuditLog::count())->toBe(0);

    $this->stripe->recover();
    expect(fn () => $service->refresh($paid['subscription']->fresh(), $admin))->toThrow(SubscriptionRefreshException::class, 'a moment ago');
});

test('local manual subscriptions cannot be refreshed', function () {
    $organization = Organization::factory()->create();
    $manual = Subscription::query()->forceCreate(['organization_id' => $organization->id, 'provider' => 'manual', 'provider_subscription_id' => 'manual_1', 'plan' => 'pro', 'status' => 'active']);

    expect(fn () => app(SubscriptionRefresh::class)->refresh($manual, billingAdmin()))->toThrow(SubscriptionRefreshException::class, 'no external billing provider');
});

test('the refresh action in the panel confirms and updates the record', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin());
    $this->stripe->subscriptions[$paid['remote']]['status'] = 'past_due';

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])
        ->callAction('refresh')
        ->assertNotified('Refreshed from Stripe');

    expect($paid['subscription']->fresh()->status->value)->toBe('past_due');
});

test('the refresh action is hidden from administrators who may not use it', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin(PlatformRole::BillingAdmin));

    Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id])->assertActionHidden('refresh');
});

// Secrets

test('no secret keys or webhook secrets appear on the subscription pages', function () {
    $paid = paidBusiness();
    $this->actingAs(billingAdmin());

    $list = Livewire::test(ListSubscriptions::class);
    $view = Livewire::test(ViewSubscription::class, ['record' => $paid['subscription']->id]);

    foreach ([$list, $view] as $page) {
        $page->assertDontSee('sk_test_fake_secret')->assertDontSee('whsec_test_secret');
    }
});

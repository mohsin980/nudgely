<?php

use App\Billing\ProviderSubscription;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\QuotaState;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform\PlatformRole;
use App\Filament\Pages\UsageDetails;
use App\Filament\Pages\UsageMonitoring;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use App\Services\Customers\CustomerService;
use App\Services\Platform\UsageMonitor;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| SA-09: Usage and quota monitoring
|--------------------------------------------------------------------------
*/

function usageOrg(string $name = 'Acme', string $timezone = 'America/Chicago'): Organization
{
    return Organization::factory()->create(['name' => $name, 'timezone' => $timezone]);
}

function sentMessage(Organization $organization, MessageStatus $status = MessageStatus::Sent, string $at = 'now', MessageDirection $direction = MessageDirection::Outbound): Message
{
    $connection = EmailConnection::where('organization_id', $organization->id)->first() ?? EmailConnection::factory()->verified()->default()->create(['organization_id' => $organization->id]);

    return Message::factory()->create(['email_connection_id' => $connection->id, 'organization_id' => $organization->id, 'direction' => $direction, 'status' => $status,
        'created_at' => $at === 'now' ? now() : CarbonImmutable::parse($at, 'UTC')]);
}

function newEstimate(Organization $organization, string $at = 'now'): Estimate
{
    $customer = Customer::where('organization_id', $organization->id)->first() ?? Customer::factory()->for($organization)->create(['is_demo' => true]);

    return Estimate::factory()->create(['customer_id' => $customer->id, 'created_at' => $at === 'now' ? now() : CarbonImmutable::parse($at, 'UTC')]);
}

function usageAdmin(PlatformRole $role = PlatformRole::SupportAdmin): User
{
    return platformAdmin($role, ['organization_id' => null]);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Quota states

test('quota states mark nearing, at and over a limit exactly where enforcement does', function () {
    $state = fn (int $used, ?int $limit) => QuotaState::for($used, $limit, 80);

    expect($state(7, 10))->toBe(QuotaState::Ok)
        ->and($state(8, 10))->toBe(QuotaState::Near)
        ->and($state(9, 10))->toBe(QuotaState::Near)
        ->and($state(10, 10))->toBe(QuotaState::AtLimit)
        ->and($state(11, 10))->toBe(QuotaState::Over)
        ->and($state(0, 0))->toBe(QuotaState::AtLimit)
        ->and($state(1, 0))->toBe(QuotaState::Over)
        ->and($state(999_999, null))->toBe(QuotaState::Unlimited)
        ->and($state(79, 100))->toBe(QuotaState::Ok)
        ->and($state(80, 100))->toBe(QuotaState::Near);
});

test('"at limit" in the monitor means exactly that the backend refuses one more', function () {
    tightFreePlan(['customers' => 3]);
    $organization = usageOrg();
    $entitlements = app(EntitlementService::class);

    foreach ([0, 1, 2, 3, 4] as $count) {
        Customer::query()->where('organization_id', $organization->id)->forceDelete();
        Customer::factory()->count($count)->for($organization)->create();
        $row = app(UsageMonitor::class)->rows(collect([$organization->fresh()]))[$organization->id];
        $state = $row['limits'][LimitKey::Customers->value]['state'];

        expect($entitlements->allows($organization, LimitKey::Customers))->toBe(! in_array($state, [QuotaState::AtLimit, QuotaState::Over], true), "{$count} customers");
    }
});

test('the backend, not the page, refuses to create past a limit', function () {
    tightFreePlan(['customers' => 2]);
    ['owner' => $owner, 'organization' => $organization] = teamBusiness();
    Customer::query()->where('organization_id', $organization->id)->forceDelete();
    Customer::factory()->count(1)->for($organization)->create();

    app(CustomerService::class)->create($owner, ['first_name' => 'Second', 'last_name' => 'Customer', 'email' => 'second@example.com']);

    expect(fn () => app(CustomerService::class)->create($owner, ['first_name' => 'Third', 'last_name' => 'Customer', 'email' => 'third@example.com']))
        ->toThrow(ValidationException::class, 'customer');
    expect(Customer::where('organization_id', $organization->id)->count())->toBe(2);
});

// Calculations: consistency with the enforcing service

test('the batched numbers equal the per-organization numbers the backend uses', function () {
    $orgs = collect();

    foreach ([['A', 'America/Chicago'], ['B', 'UTC'], ['C', 'Asia/Tokyo']] as [$name, $tz]) {
        $organization = usageOrg($name, $tz);
        Customer::factory()->count(2)->for($organization)->create();
        User::factory()->staff()->for($organization)->create();
        sentMessage($organization);
        sentMessage($organization, MessageStatus::Failed);
        sentMessage($organization, MessageStatus::Received, direction: MessageDirection::Inbound);
        newEstimate($organization);
        $orgs->push($organization->fresh());
    }

    $usage = app(UsageService::class);
    $batched = $usage->usageForMany($orgs, $usage->currentSubscriptions($orgs->pluck('id')->all()));

    foreach ($orgs as $organization) {
        foreach (LimitKey::cases() as $key) {
            expect($batched[$organization->id][$key->value])->toBe($usage->usage($organization, $key), "{$organization->name} {$key->value}");
        }
    }
});

test('a subscription period replaces the calendar month, and its boundaries are [start, end)', function () {
    $organization = usageOrg('Acme', 'UTC');
    app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_usage', 'starter', SubscriptionStatus::Active,
        currentPeriodStart: CarbonImmutable::parse('2026-10-10 00:00:00', 'UTC'), currentPeriodEnd: CarbonImmutable::parse('2026-11-10 00:00:00', 'UTC')));
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    sentMessage($organization, at: '2026-10-09 23:59:59'); // before the period
    sentMessage($organization, at: '2026-10-10 00:00:00'); // first instant: counted
    sentMessage($organization, at: '2026-11-09 23:59:59'); // last instant: counted
    sentMessage($organization, at: '2026-11-10 00:00:00'); // next period: not counted

    $row = app(UsageMonitor::class)->rows(collect([$organization->fresh()]))[$organization->id];
    expect($row['limits']['outbound_emails']['used'])->toBe(2)->and($row['plan_key'])->toBe('starter');
});

test('without a subscription the month is the organization\'s own calendar month, in its timezone', function () {
    $organization = usageOrg('Acme', 'America/Chicago');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    sentMessage($organization, at: '2026-10-01 04:59:59'); // Sept 30, 11:59 PM in Chicago
    sentMessage($organization, at: '2026-10-01 05:00:00'); // Oct 1, 12:00 AM in Chicago
    sentMessage($organization, at: '2026-11-01 04:59:59'); // Oct 31, 11:59 PM in Chicago
    sentMessage($organization, at: '2026-11-01 05:00:00'); // Nov 1 in Chicago

    $row = app(UsageMonitor::class)->rows(collect([$organization->fresh()]))[$organization->id];
    expect($row['limits']['outbound_emails']['used'])->toBe(2);
});

test('only sends that count are counted, and retries or repeated provider events never add up', function () {
    $organization = usageOrg('Acme', 'UTC');
    $monitor = fn () => app(UsageMonitor::class)->rows(collect([$organization->fresh()]))[$organization->id]['limits']['outbound_emails']['used'];

    $retried = sentMessage($organization, MessageStatus::Queued);
    $retried->forceFill(['send_attempts' => 4])->save();
    sentMessage($organization, MessageStatus::Failed);
    sentMessage($organization, MessageStatus::Received, direction: MessageDirection::Inbound);

    expect($monitor())->toBe(1); // one message, however many attempts

    foreach ([MessageStatus::Sending, MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Delivered, MessageStatus::Complained] as $status) {
        $retried->forceFill(['status' => $status])->save(); // repeated/duplicate provider events change state, not the count
    }

    expect($monitor())->toBe(1);
});

test('new estimates count once; revisions do not', function () {
    $organization = usageOrg('Acme', 'UTC');
    $first = newEstimate($organization);
    Estimate::factory()->create(['customer_id' => $first->customer_id, 'revision' => 2, 'estimate_number' => $first->estimate_number, 'revision_of_id' => $first->id]);

    $row = app(UsageMonitor::class)->rows(collect([$organization->fresh()]))[$organization->id];
    expect($row['limits']['estimates']['used'])->toBe(1);
});

test('lifetime totals are kept apart from the current period', function () {
    $organization = usageOrg('Acme', 'UTC');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    sentMessage($organization, at: '2026-08-05 10:00:00');
    sentMessage($organization, at: '2026-10-05 10:00:00');
    sentMessage($organization, at: '2026-10-14 10:00:00');

    $activity = app(UsageMonitor::class)->activity($organization->fresh())['Outbound emails (counted against the plan)'];

    expect($activity)->toBe(['period' => 2, 'last_30_days' => 2, 'lifetime' => 3]);
});

// The list

test('the list shows usage against limits with plan and billing period', function () {
    $this->actingAs(usageAdmin());
    $organization = usageOrg('Acme Plumbing');
    Customer::factory()->count(2)->for($organization)->create();

    Livewire::test(UsageMonitoring::class)
        ->assertSee('Acme Plumbing')->assertSee('Free')->assertSee('2 / 100 (2%)');
});

test('search finds organizations by name or exact ID and the list is paginated', function () {
    $this->actingAs(usageAdmin());
    $acme = usageOrg('Acme Plumbing');
    foreach (range(1, 30) as $i) {
        usageOrg("Filler {$i}");
    }
    $zenith = usageOrg('Zenith Roofing');

    $monitor = app(UsageMonitor::class);
    expect($monitor->list([], 'plumb', 'name', 'asc', 1, 25)->pluck('id')->all())->toBe([$acme->id])
        ->and($monitor->list([], (string) $zenith->id, 'name', 'asc', 1, 25)->pluck('id')->all())->toBe([$zenith->id])
        ->and($monitor->list([], null, 'name', 'asc', 1, 25))->toHaveCount(25)
        ->and($monitor->list([], null, 'name', 'asc', 2, 25))->toHaveCount(7)
        ->and($monitor->list([], null, 'name', 'asc', 1, 25)->total())->toBe(32);
});

test('quota status and plan filters find the right organizations, worst first', function () {
    tightFreePlan(['customers' => 10]);
    $ok = usageOrg('Quiet');
    $near = usageOrg('Nearly');
    $at = usageOrg('Full');
    $over = usageOrg('Beyond');
    Customer::factory()->count(1)->for($ok)->create();
    Customer::factory()->count(8)->for($near)->create();
    Customer::factory()->count(10)->for($at)->create();
    Customer::factory()->count(12)->for($over)->create();
    $monitor = app(UsageMonitor::class);
    $ids = fn (array $filters) => $monitor->list($filters, null, 'name', 'asc', 1, 50)->pluck('name')->all();

    expect($ids(['state' => 'near']))->toBe(['Nearly'])
        ->and($ids(['state' => 'at_limit']))->toBe(['Full'])
        ->and($ids(['state' => 'over']))->toBe(['Beyond'])
        ->and($ids(['state' => 'attention']))->toBe(['Beyond', 'Full', 'Nearly'])
        ->and($ids(['state' => 'ok']))->toBe(['Quiet'])
        ->and($ids(['plan' => 'pro']))->toBe([])
        ->and($ids(['plan' => 'free', 'state' => 'over']))->toBe(['Beyond']);
});

test('organizations on a paid plan are measured against that plan', function () {
    $organization = usageOrg('Paid Co');
    app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_paid', 'pro', SubscriptionStatus::Active));

    $rows = app(UsageMonitor::class)->list(['plan' => 'pro'], null, 'name', 'asc', 1, 25);

    expect($rows->pluck('name')->all())->toBe(['Paid Co'])->and($rows->first()['limits']['customers']['limit'])->toBe(2000);
});

test('query count for a page does not grow with the number of organizations', function () {
    $monitor = app(UsageMonitor::class);
    $count = function () use ($monitor) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $monitor->list([], null, 'name', 'asc', 1, 25);

        return count(DB::getQueryLog());
    };

    usageOrg('One');
    $count();
    $small = $count();

    foreach (range(1, 24) as $i) {
        $organization = usageOrg("Org {$i}");
        Customer::factory()->for($organization)->create();
        sentMessage($organization);
    }

    expect($count())->toBe($small);
});

test('the filtered scan stays within a fixed number of queries per 200 organizations', function () {
    $monitor = app(UsageMonitor::class);

    foreach (range(1, 40) as $i) {
        usageOrg("Org {$i}");
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $monitor->list(['state' => 'over'], null, 'name', 'asc', 1, 25);

    expect(count(DB::getQueryLog()))->toBeLessThan(25);
});

// Access

test('administrators with view_usage can open the page; customers and guests cannot', function () {
    $this->get('/admin/usage')->assertRedirect('/admin/login');

    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin, PlatformRole::SuperAdmin] as $role) {
        $this->actingAs(usageAdmin($role));
        expect(UsageMonitoring::canAccess())->toBeTrue();
    }

    $this->actingAs(User::factory()->owner()->create());
    $this->get('/admin/usage')->assertForbidden();
    expect(UsageMonitoring::canAccess())->toBeFalse();
});

test('the details page shows period, activity and what is unavailable, and needs view_usage', function () {
    $organization = usageOrg('Acme Plumbing');
    sentMessage($organization);

    $this->get("/admin/usage/{$organization->id}")->assertRedirect('/admin/login');

    $this->actingAs(usageAdmin());
    Livewire::test(UsageDetails::class, ['organizationId' => $organization->id])
        ->assertSee('Acme Plumbing')->assertSee('Current plan period')->assertSee('Lifetime')->assertSee('Opportunities created')->assertSee('Unavailable');
    $this->get("/admin/usage/{$organization->id}")->assertOk();

    $this->actingAs(User::factory()->owner()->create());
    $this->get("/admin/usage/{$organization->id}")->assertForbidden();
});

test('the capacity section reports counts only, never job payloads', function () {
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendMail', 'data' => ['secret' => 'payload-secret-value']]), 'exception' => 'boom', 'failed_at' => now()]);
    $this->actingAs(usageAdmin());

    Livewire::test(UsageMonitoring::class)->assertSee('failed jobs: 1')->assertDontSee('payload-secret-value');
});

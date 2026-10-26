<?php

use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Platform\PlatformRole;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Platform\PlanSettingsException;
use App\Filament\Pages\Plans;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlanSetting;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Platform\PlanSettingsService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| SA-07: Plans and feature-limit management
|--------------------------------------------------------------------------
*/

function proLimits(array $override = []): array
{
    $plan = app(PlanCatalog::class)->get('pro');

    return [...collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $k) => [$k->value => $plan->limit($k)])->all(), ...$override];
}

function planAdmin(PlatformRole $role = PlatformRole::SuperAdmin): User
{
    return platformAdmin($role, ['organization_id' => null]);
}

function subscribe(Organization $organization, string $plan = 'pro', string $status = 'active'): Subscription
{
    static $n = 0;

    return Subscription::query()->forceCreate([
        'organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_plan_'.++$n,
        'plan' => $plan, 'status' => $status,
    ]);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Viewing

test('administrators with view_plans see every plan with price, interval, provider ID and limits', function () {
    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin, PlatformRole::SuperAdmin] as $role) {
        $this->actingAs(planAdmin($role));

        Livewire::test(Plans::class)
            ->assertSee('Free')->assertSee('Starter')->assertSee('Pro')
            ->assertSee('$79.00')->assertSee('Monthly');
    }

    $this->actingAs(planAdmin());
    Livewire::test(Plans::class)->assertCanSeeTableRecords(['free', 'starter', 'pro'])->assertSee('Provider price ID');
});

test('customers, guests and admins without view_plans cannot open the plans page', function () {
    $this->get('/admin/plans')->assertRedirect('/admin/login');

    $this->actingAs(User::factory()->owner()->create());
    $this->get('/admin/plans')->assertForbidden();
    expect(Plans::canAccess())->toBeFalse();
});

// Editing

test('only administrators with manage_plans may edit', function () {
    $service = app(PlanSettingsService::class);

    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin] as $role) {
        $admin = planAdmin($role);
        expect(fn () => $service->update('pro', 'Pro', proLimits(['customers' => 5000]), $admin, 'Support should not edit plans'))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $service->reset('pro', $admin, 'Support should not reset plans'))->toThrow(AuthorizationException::class);
    }

    expect(fn () => $service->update('pro', 'Pro', proLimits(['customers' => 5000]), User::factory()->owner()->create(), 'A customer editing plans'))
        ->toThrow(AuthorizationException::class);
    expect(PlanSetting::count())->toBe(0)->and(PlatformAuditLog::where('outcome', 'success')->count())->toBe(0)->and(PlatformAuditLog::where('outcome', 'denied')->count())->toBe(5);
});

test('the edit action is hidden from administrators who may not manage plans', function () {
    $this->actingAs(planAdmin(PlatformRole::BillingAdmin));

    Livewire::test(Plans::class)->assertActionHidden(TestAction::make('edit')->table('pro'));
});

test('a super admin edits limits: validated, audited, applied, and billing is untouched', function () {
    Http::fake();
    $admin = planAdmin();
    $org = Organization::factory()->create(['timezone' => 'UTC']);
    $subscription = subscribe($org);
    $before = $subscription->fresh()->getAttributes();
    $configured = app(PlanCatalog::class)->get('pro');

    $impact = app(PlanSettingsService::class)->update('pro', 'Pro Plus', proLimits(['customers' => 3000, 'estimates' => null]), $admin, '  Raising limits  for the autumn promo ');

    $pro = app(PlanCatalog::class)->get('pro');
    expect($pro->name)->toBe('Pro Plus')
        ->and($pro->limit(LimitKey::Customers))->toBe(3000)
        ->and($pro->limit(LimitKey::Estimates))->toBeNull()
        ->and($pro->priceCents)->toBe($configured->priceCents)
        ->and($pro->interval)->toBe($configured->interval)
        ->and($pro->providerPriceId)->toBe($configured->providerPriceId)
        ->and($impact['subscribers'])->toBe(1);

    $log = PlatformAuditLog::sole();
    expect($log->action)->toBe('plan_updated')->and($log->actor_id)->toBe($admin->id)->and($log->reason)->toBe('Raising limits for the autumn promo')
        ->and($log->changes['fields']['name'])->toEqual(['from' => 'Pro', 'to' => 'Pro Plus'])
        ->and($log->changes['fields']['limits.customers'])->toEqual(['from' => 2000, 'to' => 3000]);

    // The subscription row and the billing provider were not touched.
    expect($subscription->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
});

test('a limit change reaches existing subscribers at once and is enforced server-side', function () {
    config(['billing.enforce_limits' => true]);
    $admin = planAdmin();
    $org = Organization::factory()->create(['timezone' => 'UTC']);
    subscribe($org);
    User::factory()->count(3)->create(['organization_id' => $org->id]);
    $entitlements = app(EntitlementService::class);

    expect($entitlements->canAddTeamMember($org))->toBeTrue();

    $impact = app(PlanSettingsService::class)->update('pro', 'Pro', proLimits(['team_members' => 3]), $admin, 'Tightening seats for abuse control');

    expect($impact['over'])->toBe(['team_members' => 0])
        ->and($entitlements->canAddTeamMember($org))->toBeFalse()
        ->and(fn () => $entitlements->assertAllows($org, LimitKey::TeamMembers))->toThrow(PlanLimitException::class);
});

test('lowering a limit below current usage deletes nothing and the preview says who is affected', function () {
    $admin = planAdmin();
    $org = Organization::factory()->create(['timezone' => 'UTC']);
    subscribe($org);
    User::factory()->count(5)->create(['organization_id' => $org->id]);
    Customer::factory()->count(2)->create(['organization_id' => $org->id, 'is_demo' => false]);

    $impact = app(PlanSettingsService::class)->impact('pro', proLimits(['team_members' => 3]));
    expect($impact)->toMatchArray(['subscribers' => 1, 'over' => ['team_members' => 1]]);

    app(PlanSettingsService::class)->update('pro', 'Pro', proLimits(['team_members' => 3]), $admin, 'Lower seats, existing data kept');

    expect(User::where('organization_id', $org->id)->count())->toBe(5)->and(Customer::where('organization_id', $org->id)->count())->toBe(2)
        ->and(Subscription::where('organization_id', $org->id)->sole()->status->value)->toBe('active');
});

test('reset restores the configured name and limits and is audited', function () {
    $admin = planAdmin();
    $service = app(PlanSettingsService::class);
    $service->update('pro', 'Pro Plus', proLimits(['customers' => 3000]), $admin, 'Temporary promotion limits');

    $service->reset('pro', $admin, 'Promotion finished, back to normal');

    $pro = app(PlanCatalog::class)->get('pro');
    expect($pro->name)->toBe('Pro')->and($pro->limit(LimitKey::Customers))->toBe(2000)
        ->and(PlanSetting::count())->toBe(0)
        ->and(PlatformAuditLog::orderBy('id')->pluck('action')->all())->toBe(['plan_updated', 'plan_reset']);
});

// Validation

test('invalid prices, limits and names are rejected', function () {
    $admin = planAdmin();
    $service = app(PlanSettingsService::class);

    $cases = [
        'negative limit' => [['customers' => -1], 'Pro', 'limits.customers'],
        'fractional limit' => [['customers' => '1.5'], 'Pro', 'limits.customers'],
        'text limit' => [['customers' => 'lots'], 'Pro', 'limits.customers'],
        'absurd limit' => [['customers' => 5_000_000], 'Pro', 'limits.customers'],
        'zero team members' => [['team_members' => 0], 'Pro', 'limits.team_members'],
        'blank name' => [[], ' ', 'name'],
        'html name' => [[], '<b>Pro</b>', 'name'],
        'duplicate name' => [[], 'starter', 'name'],
        'dearer plan allows less than a cheaper one' => [['customers' => 400], 'Pro', 'limits.customers'],
        'dearer plan loses unlimited' => [['automations' => 10], 'Pro', 'limits.automations'],
    ];

    foreach ($cases as $label => [$override, $name, $field]) {
        try {
            $service->update('pro', $name, proLimits($override), $admin, 'Testing invalid input');
            $this->fail("Expected [{$label}] to be rejected.");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    }

    expect(PlanSetting::count())->toBe(0)->and(PlatformAuditLog::where('outcome', 'success')->count())->toBe(0);
});

test('a cheaper plan cannot be raised above a dearer one', function () {
    expect(fn () => app(PlanSettingsService::class)->update('starter', 'Starter', [...proLimits(), 'customers' => 3000], planAdmin(), 'Starter above Pro is wrong'))
        ->toThrow(ValidationException::class);
});

test('a reason is required and a no-op edit is refused', function () {
    $admin = planAdmin();
    $service = app(PlanSettingsService::class);

    expect(fn () => $service->update('pro', 'Pro', proLimits(['customers' => 2500]), $admin, 'short'))->toThrow(PlanSettingsException::class)
        ->and(fn () => $service->update('pro', 'Pro', proLimits(), $admin, 'Saving without changing anything'))->toThrow(PlanSettingsException::class, 'Nothing was changed.');
});

test('plans cannot be created or deleted and unknown plans are refused', function () {
    $admin = planAdmin();

    expect(fn () => app(PlanSettingsService::class)->update('platinum', 'Platinum', proLimits(), $admin, 'There is no such plan'))
        ->toThrow(PlanSettingsException::class);

    $keysBefore = array_keys(app(PlanCatalog::class)->all());
    app(PlanSettingsService::class)->update('pro', 'Pro', proLimits(['customers' => 2500]), $admin, 'Plain limit change');
    expect(array_keys(app(PlanCatalog::class)->all()))->toBe($keysBefore);
});

test('a plan with active subscribers stays valid and subscribed after any edit', function () {
    $org = Organization::factory()->create(['timezone' => 'UTC']);
    subscribe($org);
    app(PlanSettingsService::class)->update('pro', 'Pro Renamed', proLimits(['customers' => 600]), planAdmin(), 'Rename and tighten the plan');

    $subscription = Subscription::where('organization_id', $org->id)->sole();
    expect($subscription->plan)->toBe('pro')->and($subscription->planDefinition()?->name)->toBe('Pro Renamed')
        ->and(app(EntitlementService::class)->plan($org)->key)->toBe('pro');
});

test('bad override rows can never break billing', function () {
    DB::table('plan_settings')->insert([
        ['plan_key' => 'pro', 'name' => '  ', 'limits' => json_encode(['customers' => -5, 'estimates' => 'x', 'ghost' => 9]), 'created_at' => now(), 'updated_at' => now()],
        ['plan_key' => 'platinum', 'name' => 'Ghost', 'limits' => json_encode(['customers' => 1]), 'created_at' => now(), 'updated_at' => now()],
    ]);
    app(PlanCatalog::class)->refresh();

    $catalog = app(PlanCatalog::class);
    expect($catalog->get('pro')->limit(LimitKey::Customers))->toBe(2000)
        ->and($catalog->get('pro')->name)->toBe('Pro')
        ->and($catalog->find('platinum'))->toBeNull();
});

// Panel

test('the edit action in the panel saves, audits and reports the effect', function () {
    $this->actingAs(planAdmin());
    $org = Organization::factory()->create(['timezone' => 'UTC']);
    subscribe($org);

    Livewire::test(Plans::class)
        ->callAction(TestAction::make('edit')->table('pro'), [
            'name' => 'Pro', 'limit_customers' => 2500, 'limit_automations' => 50, 'limit_team_members' => 10,
            'limit_outbound_emails' => 10000, 'limit_estimates' => 1000, 'reason' => 'Increase customers for growth',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Plan updated');

    expect(app(PlanCatalog::class)->get('pro')->limit(LimitKey::Customers))->toBe(2500)
        ->and(PlatformAuditLog::sole()->reason)->toBe('Increase customers for growth');
});

test('the panel action rejects invalid input without saving', function () {
    $this->actingAs(planAdmin());

    Livewire::test(Plans::class)
        ->callAction(TestAction::make('edit')->table('pro'), [
            'name' => 'Pro', 'limit_customers' => 10, 'limit_automations' => 50, 'limit_team_members' => 10,
            'limit_outbound_emails' => 10000, 'limit_estimates' => 1000, 'reason' => 'Lower than the cheaper plan',
        ]);

    expect(PlanSetting::count())->toBe(0)->and(app(PlanCatalog::class)->get('pro')->limit(LimitKey::Customers))->toBe(2000);
});

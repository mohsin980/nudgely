<?php

use App\Billing\AddonCatalog;
use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Models\PlanSetting;
use Database\Seeders\PackageSeeder;

test('the package seeder creates every plan and add-on, with their prices and details', function () {
    $this->seed(PackageSeeder::class);

    expect(PlanSetting::query()->where('kind', PlanSetting::KIND_PLAN)->count())->toBe(3)
        ->and(PlanSetting::query()->where('kind', PlanSetting::KIND_ADDON)->count())->toBe(6);

    $starter = app(PlanCatalog::class)->get('starter');
    expect($starter->priceCents)->toBe(2900)
        ->and($starter->priceFor('PKR'))->toBe(800000)
        ->and($starter->limit(LimitKey::Customers))->toBe(500);

    expect(app(AddonCatalog::class)->find('ai_assistant')['price_cents'])->toBe(1900)
        ->and(app(AddonCatalog::class)->validate())->toBe([]);
});

test('running the seeder again never creates copies', function () {
    $this->seed(PackageSeeder::class);
    $this->seed(PackageSeeder::class);

    expect(PlanSetting::query()->count())->toBe(9)
        ->and(PlanSetting::query()->where('plan_key', 'starter')->count())->toBe(1);
});

test('a changed value is updated in the database when the seeder runs', function () {
    $this->seed(PackageSeeder::class);
    PlanSetting::query()->where('plan_key', 'starter')->update(['name' => 'Starter Plus']);

    $this->seed(PackageSeeder::class);

    expect(PlanSetting::query()->where('plan_key', 'starter')->value('name'))->toBe('Starter');
});

test('the seeder does not read config: changing config does not change the database', function () {
    config(['billing.plans.starter.name' => 'Config Name', 'billing.addons.customers.price_cents' => 9999]);

    $this->seed(PackageSeeder::class);

    expect(PlanSetting::query()->where('plan_key', 'starter')->value('name'))->toBe('Starter')
        ->and(PlanSetting::query()->where('plan_key', 'customers')->value('prices')['USD'])->toBe(1000);
});

test('the seeder never clears Stripe prices or their legacy history set by an administrator', function () {
    $this->seed(PackageSeeder::class);
    PlanSetting::query()->where('plan_key', 'starter')->update([
        'provider_price_ids' => ['USD' => 'price_starter_live'],
        'legacy_provider_price_ids' => ['price_starter_old'],
    ]);

    $this->seed(PackageSeeder::class);

    $row = PlanSetting::query()->where('plan_key', 'starter')->first();
    expect($row->provider_price_ids)->toBe(['USD' => 'price_starter_live'])
        ->and($row->legacy_provider_price_ids)->toBe(['price_starter_old']);
});

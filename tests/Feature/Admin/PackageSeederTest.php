<?php

use App\Billing\AddonCatalog;
use App\Billing\PlanCatalog;
use App\Models\PlanSetting;
use Database\Seeders\PackageSeeder;

test('the package seeder writes every configured plan and add-on, and the catalogs read them back', function () {
    $this->seed(PackageSeeder::class);

    expect(PlanSetting::query()->where('kind', PlanSetting::KIND_PLAN)->count())->toBe(count(config('billing.plans')))
        ->and(PlanSetting::query()->where('kind', PlanSetting::KIND_ADDON)->count())->toBe(count(config('billing.addons')));

    $starter = app(PlanCatalog::class)->get('starter');
    expect($starter->priceCents)->toBe(2900)
        ->and($starter->priceFor('PKR'))->toBe(800000);

    expect(app(AddonCatalog::class)->find('ai_assistant')['price_cents'])->toBe(1900)
        ->and(app(AddonCatalog::class)->validate())->toBe([]);
});

test('running the package seeder again changes nothing, and keeps an administrator\'s edit', function () {
    $this->seed(PackageSeeder::class);
    PlanSetting::query()->where('plan_key', 'starter')->update(['name' => 'Starter Plus']);

    $this->seed(PackageSeeder::class);

    expect(PlanSetting::query()->count())->toBe(count(config('billing.plans')) + count(config('billing.addons')))
        ->and(PlanSetting::query()->where('plan_key', 'starter')->value('name'))->toBe('Starter Plus');
});

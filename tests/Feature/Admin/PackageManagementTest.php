<?php

use App\Billing\AddonCatalog;
use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Platform\PlatformRole;
use App\Exceptions\Billing\BillingException;
use App\Exceptions\Platform\PlanSettingsException;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Platform\PackageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeStripe;

/*
|--------------------------------------------------------------------------
| Packages managed from the admin panel: plans and add-ons, their prices, Stripe prices and details.
|--------------------------------------------------------------------------
*/

function packageAdmin(): User
{
    return platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
}

/** Every limit set to the same number. */
function allLimits(int $value = 10): array
{
    return collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => $value])->all();
}

/** A new plan, as the admin form submits it (amounts in minor units). */
function growthPlan(array $overrides = []): array
{
    return array_merge([
        'key' => 'growth', 'name' => 'Growth', 'description' => 'For growing crews.', 'active' => true,
        'prices' => ['USD' => 4900, 'PKR' => 1200000],
        'provider_price_ids' => ['USD' => 'price_growth_usd', 'PKR' => 'price_growth_pkr'],
        'features' => ['estimates', 'follow_ups'],
        // Between Starter and Pro, so the price ladder holds.
        'limits' => ['customers' => 800, 'automations' => 20, 'team_members' => 5, 'outbound_emails' => 5000, 'estimates' => 500, 'ai_conversations' => 150],
    ], $overrides);
}

/** Runs a save and returns the validation errors it raised (empty when it saved). */
function saveErrors(callable $save): array
{
    try {
        $save();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('an administrator creates a plan with its prices, description, features and limits, and it is sold', function () {
    app(PackageService::class)->save(packageAdmin(), 'plan', null, growthPlan(), 'Launching the growth plan for crews.');

    $plan = app(PlanCatalog::class)->get('growth');

    expect($plan->name)->toBe('Growth')
        ->and($plan->description)->toBe('For growing crews.')
        ->and($plan->priceCents)->toBe(4900)
        ->and($plan->priceFor('PKR'))->toBe(1200000)
        ->and($plan->providerPriceIdFor('PKR'))->toBe('price_growth_pkr')
        ->and($plan->features)->toBe(['estimates', 'follow_ups'])
        ->and($plan->limit(LimitKey::Customers))->toBe(800)
        ->and($plan->active)->toBeTrue()
        ->and(collect(app(BillingService::class)->plans())->pluck('key')->all())->toContain('growth')
        ->and(PlatformAuditLog::query()->where('action', 'package_saved')->where('target_id', 'growth')->exists())->toBeTrue();
});

test('a plan turned off is no longer sold, and nobody can start or move to it', function () {
    $admin = packageAdmin();
    app(PackageService::class)->save($admin, 'plan', null, growthPlan(), 'Creating the growth plan for testing.');
    app(PackageService::class)->save($admin, 'plan', 'growth', growthPlan(['active' => false]), 'Pausing the growth plan for now.');

    $owner = teamBusiness()['owner'];

    expect(collect(app(BillingService::class)->plans())->pluck('key')->all())->not->toContain('growth')
        ->and(app(PlanCatalog::class)->get('growth')->active)->toBeFalse()
        ->and(fn () => app(BillingService::class)->startCheckout($owner, 'growth', 'https://a', 'https://b'))
        ->toThrow(BillingException::class, 'no longer available');
});

test('the default plan cannot be turned off', function () {
    $errors = saveErrors(fn () => app(PackageService::class)->save(packageAdmin(), 'plan', 'free', [
        'name' => 'Free', 'active' => false, 'prices' => ['USD' => 0], 'provider_price_ids' => [], 'features' => [], 'limits' => allLimits(5),
    ], 'Trying to switch off the default plan.'));

    expect($errors)->toHaveKey('active')
        ->and(app(PlanCatalog::class)->get('free')->active)->toBeTrue();
});

test('invalid values are refused, and each field says what is wrong', function () {
    $errors = saveErrors(fn () => app(PackageService::class)->save(packageAdmin(), 'plan', null, growthPlan([
        'key' => 'Bad Key',
        'prices' => ['USD' => null, 'PKR' => 'twelve'],
        'provider_price_ids' => ['USD' => 'sk_live_not_a_price'],
        'features' => ['teleport'],
        'limits' => [...allLimits(10), LimitKey::Customers->value => -4],
    ]), 'Testing validation messages.'));

    expect($errors)->toHaveKeys(['key', 'prices.USD', 'prices.PKR', 'provider_price_ids.USD', 'features', 'limits.'.LimitKey::Customers->value]);
});

test('a Stripe price ID already used by another package is refused', function () {
    $admin = packageAdmin();
    app(PackageService::class)->save($admin, 'plan', null, growthPlan(), 'Creating the growth plan for testing.');

    $errors = saveErrors(fn () => app(PackageService::class)->save($admin, 'plan', null, growthPlan([
        'key' => 'scale', 'name' => 'Scale', 'provider_price_ids' => ['USD' => 'price_growth_usd', 'PKR' => 'price_scale_pkr'],
    ]), 'Reusing the growth price for another plan.'));

    expect($errors)->toHaveKey('provider_price_ids.USD');
});

test('with Stripe, a price must exist, be active, bill monthly, and match the currency and amount entered', function () {
    $stripe = FakeStripe::install();
    $stripe->addPrice('price_growth_usd', 'usd', 4900);
    $stripe->addPrice('price_growth_pkr', 'pkr', 1200000);
    $admin = packageAdmin();

    // The good prices save.
    app(PackageService::class)->save($admin, 'plan', null, growthPlan(), 'Creating the growth plan against Stripe.');
    expect(app(PlanCatalog::class)->get('growth')->providerPriceId)->toBe('price_growth_usd');

    $cases = [
        'wrong amount' => ['price_wrong', ['usd', 3900], 'charges a different amount'],
        'wrong currency' => ['price_pk', ['pkr', 4900], 'is in PKR, not USD'],
        'inactive' => ['price_off', ['usd', 4900, false], 'is not active'],
        'yearly' => ['price_year', ['usd', 4900, true, 'year'], 'must bill every month'],
        'unknown' => ['price_missing', null, 'does not recognise'],
    ];

    foreach ($cases as $case => [$id, $details, $message]) {
        if ($details !== null) {
            $stripe->addPrice($id, ...$details);
        }

        $errors = saveErrors(fn () => app(PackageService::class)->save($admin, 'plan', 'growth', growthPlan([
            'provider_price_ids' => ['USD' => $id, 'PKR' => 'price_growth_pkr'],
        ]), "Testing the {$case} case."));

        expect($errors)->toHaveKey('provider_price_ids.USD')
            ->and(implode(' ', $errors['provider_price_ids.USD'] ?? []))->toContain($message);
    }
});

test('changing a Stripe price keeps the old one mapped to the plan, for businesses already on it', function () {
    $stripe = FakeStripe::install();
    $stripe->addPrice('price_growth_usd', 'usd', 4900);
    $stripe->addPrice('price_growth_usd_v2', 'usd', 4900);
    $stripe->addPrice('price_growth_pkr', 'pkr', 1200000);
    $admin = packageAdmin();

    app(PackageService::class)->save($admin, 'plan', null, growthPlan(), 'Creating the growth plan against Stripe.');
    app(PackageService::class)->save($admin, 'plan', 'growth', growthPlan([
        'provider_price_ids' => ['USD' => 'price_growth_usd_v2', 'PKR' => 'price_growth_pkr'],
    ]), 'Moving the growth plan to a new Stripe price.');

    $catalog = app(PlanCatalog::class);

    expect($catalog->get('growth')->providerPriceId)->toBe('price_growth_usd_v2')
        ->and($catalog->findByProviderPriceId('price_growth_usd')?->key)->toBe('growth')
        ->and($catalog->findByProviderPriceId('price_growth_usd_v2')?->key)->toBe('growth');
});

test('resetting a configured plan keeps its old Stripe prices mapped, so existing subscribers are not lost', function () {
    $stripe = FakeStripe::install();
    $stripe->addPrice('price_starter', 'usd', 2900);
    $stripe->addPrice('price_starter_v2', 'usd', 2900);
    $stripe->addPrice('price_starter_pkr', 'pkr', 800000);
    $admin = packageAdmin();

    $starter = app(PlanCatalog::class)->get('starter');
    app(PackageService::class)->save($admin, 'plan', 'starter', [
        'name' => $starter->name, 'description' => 'Changed.', 'active' => true,
        'prices' => ['USD' => 2900, 'PKR' => 800000],
        'provider_price_ids' => ['USD' => 'price_starter_v2', 'PKR' => null],
        'features' => $starter->features, 'limits' => collect(LimitKey::cases())->mapWithKeys(fn ($k) => [$k->value => $starter->limit($k)])->all(),
    ], 'Moving the starter plan to a new Stripe price.');

    app(PackageService::class)->reset($admin, 'plan', 'starter', 'Putting the starter plan back to its configuration.');

    $catalog = app(PlanCatalog::class);

    expect($catalog->get('starter')->description)->not->toBe('Changed.')
        ->and($catalog->findByProviderPriceId('price_starter')?->key)->toBe('starter')
        ->and($catalog->findByProviderPriceId('price_starter_v2')?->key)->toBe('starter');
});

test('an unchanged plan is not saved again', function () {
    $admin = packageAdmin();
    app(PackageService::class)->save($admin, 'plan', null, growthPlan(), 'Creating the growth plan for testing.');

    expect(fn () => app(PackageService::class)->save($admin, 'plan', 'growth', growthPlan(), 'Saving the same values again.'))
        ->toThrow(PlanSettingsException::class);
});

test('an add-on is created with prices, the limits it adds, and the plans it is sold with', function () {
    app(PackageService::class)->save(packageAdmin(), 'addon', null, [
        'key' => 'priority_boost', 'name' => 'Priority boost', 'description' => 'More room for the busy season.', 'active' => true,
        'applies_to' => ['starter', 'pro'], 'prices' => ['USD' => 1500, 'PKR' => 400000],
        'provider_price_ids' => ['USD' => null, 'PKR' => null], 'features' => ['custom_branding'],
        'limits' => ['customers' => 500],
    ], 'Creating the priority boost add-on.');

    $addon = app(AddonCatalog::class)->find('priority_boost');

    expect($addon['price_cents'])->toBe(1500)
        ->and($addon['prices']['PKR'])->toBe(400000)
        ->and($addon['limits'])->toBe(['customers' => 500])
        ->and(array_keys(app(AddonCatalog::class)->availableFor('starter')))->toContain('priority_boost')
        ->and(array_keys(app(AddonCatalog::class)->availableFor('free')))->not->toContain('priority_boost')
        ->and(app(AddonCatalog::class)->validate())->toBe([]);
});

test('an add-on needs a price above zero, a plan to be sold with, and something to add', function () {
    $errors = saveErrors(fn () => app(PackageService::class)->save(packageAdmin(), 'addon', null, [
        'key' => 'nothing_much', 'name' => 'Nothing much', 'active' => true, 'applies_to' => [],
        'prices' => ['USD' => 0], 'provider_price_ids' => [], 'features' => [], 'limits' => [],
    ], 'Testing add-on rules.'));

    expect($errors)->toHaveKeys(['prices.USD', 'applies_to', 'limits']);
});

test('a user without the plan permission cannot save, and the attempt is audited', function () {
    $stranger = User::factory()->create();

    expect(fn () => app(PackageService::class)->save($stranger, 'plan', null, growthPlan(), 'Trying to create a plan without permission.'))
        ->toThrow(AuthorizationException::class)
        ->and(PlatformAuditLog::query()->where('action', 'package_saved')->where('outcome', 'denied')->exists())->toBeTrue()
        ->and(app(PlanCatalog::class)->find('growth'))->toBeNull();
});

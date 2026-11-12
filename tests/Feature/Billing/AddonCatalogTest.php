<?php

use App\Billing\AddonCatalog;

test('the shipped add-ons are all sellable: every plan, feature and limit they name exists', function () {
    expect((new AddonCatalog(config('billing')))->validate())->toBe([]);
});

test('each add-on has a positive monthly price, a name and at least one plan it can be bought with', function () {
    foreach ((new AddonCatalog(config('billing')))->all() as $key => $addon) {
        expect($addon['price_cents'])->toBeGreaterThan(0, $key)
            ->and($addon['interval'])->toBe('month', $key)
            ->and($addon['applies_to'])->not->toBeEmpty($key);
    }
});

test('the AI WhatsApp assistant add-on is for Free businesses and includes 100 AI conversations', function () {
    $catalog = new AddonCatalog(config('billing'));

    expect($catalog->find('ai_assistant'))
        ->applies_to->toBe(['free'])
        ->features->toBe(['ai_assistant'])
        ->limits->toBe(['ai_conversations' => 100])
        ->and(array_keys($catalog->availableFor('free')))->toContain('ai_assistant')
        ->and(array_keys($catalog->availableFor('pro')))->not->toContain('ai_assistant');
});

test('an add-on is shown only on the plans it applies to', function () {
    $catalog = new AddonCatalog(config('billing'));

    // Custom branding is already in Starter and Pro, so the add-on is only for Free.
    expect(array_keys($catalog->availableFor('starter')))->not->toContain('custom_branding')
        ->and(array_keys($catalog->availableFor('free')))->toContain('custom_branding');
});

test('a broken add-on is reported, not sold: unknown plan, unknown limit, zero price and no effect', function () {
    $config = config('billing');
    $config['addons'] = [
        'bad_plan' => ['name' => 'X', 'price_cents' => 500, 'interval' => 'month', 'applies_to' => ['enterprise'], 'features' => [], 'limits' => ['customers' => 10]],
        'bad_limit' => ['name' => 'Y', 'price_cents' => 500, 'interval' => 'month', 'applies_to' => ['free'], 'features' => [], 'limits' => ['storage_gb' => 10]],
        'free_price' => ['name' => 'Z', 'price_cents' => 0, 'interval' => 'month', 'applies_to' => ['free'], 'features' => ['custom_branding'], 'limits' => []],
        'nothing' => ['name' => 'W', 'price_cents' => 500, 'interval' => 'month', 'applies_to' => ['free'], 'features' => [], 'limits' => []],
    ];

    $errors = (new AddonCatalog($config))->validate();

    expect(implode("\n", $errors))
        ->toContain('[bad_plan]: applies_to names an unknown plan [enterprise]')
        ->toContain('[bad_limit]: limit [storage_gb] does not exist on plan [free]')
        ->toContain('[free_price]: price_cents must be a positive')
        ->toContain('[nothing]: changes nothing');
});

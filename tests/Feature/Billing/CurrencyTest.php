<?php

use App\Billing\BillingCurrency;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\Billing\BillingInterval;
use App\Exceptions\Billing\BillingException;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Illuminate\Http\Request;
use Tests\Fakes\FakeStripe;

/*
 * Visitors from Pakistan see and pay in PKR; everyone else in USD. The country comes from a trusted CDN header.
 */

/** A request carrying a CDN country header, with a session the currency can be pinned in. */
function requestFrom(?string $country, ?string $header = 'CF-IPCountry', $session = null): Request
{
    $server = $country === null ? [] : ['HTTP_'.strtoupper(str_replace('-', '_', (string) $header)) => $country];
    $request = Request::create('/settings/billing', 'GET', [], [], [], $server);

    if ($session !== null) {
        $request->setLaravelSession($session);
    }

    return $request;
}

beforeEach(function () {
    config(['billing.geo.country_headers' => ['CF-IPCountry']]);
});

test('a visitor from Pakistan is shown PKR, and everyone else USD', function () {
    $currency = app(BillingCurrency::class);

    expect($currency->forRequest(requestFrom('PK')))->toBe('PKR')
        ->and($currency->forRequest(requestFrom('US')))->toBe('USD')
        ->and($currency->forRequest(requestFrom('AE')))->toBe('USD');
});

test('with no country header, or an unknown one, the visitor is shown USD', function () {
    $currency = app(BillingCurrency::class);

    expect($currency->forRequest(requestFrom(null)))->toBe('USD')
        ->and($currency->forRequest(requestFrom('XX')))->toBe('USD')   // Cloudflare: unknown location
        ->and($currency->forRequest(requestFrom('T1')))->toBe('USD')   // Cloudflare: Tor
        ->and($currency->forRequest(requestFrom('pakistan')))->toBe('USD');
});

test('a country header is ignored unless the CDN header is configured', function () {
    config(['billing.geo.country_headers' => []]);

    expect(app(BillingCurrency::class)->forRequest(requestFrom('PK')))->toBe('USD');
});

test('the currency is pinned for the session, so the price seen is the price charged', function () {
    $currency = app(BillingCurrency::class);
    $session = app('session.store');
    $session->start();

    expect($currency->forRequest(requestFrom('PK', 'CF-IPCountry', $session)))->toBe('PKR')
        ->and($currency->forRequest(requestFrom('US', 'CF-IPCountry', $session)))->toBe('PKR');
});

test('PKR and USD amounts are shown with two decimal places', function () {
    // Stored in paisa: 800000 paisa is PKR 8,000.
    expect(Money::format(800000, 'PKR'))->toBe('PKR 8,000.00')
        ->and(Money::format(2900, 'USD'))->toBe('$29.00')
        ->and(Money::format(800050, 'PKR'))->toBe('PKR 8,000.50');
});

test('plan prices are shown in the visitor\'s currency, with a USD fallback for a plan not sold in it', function () {
    $starter = app(PlanCatalog::class)->get('starter');
    $free = app(PlanCatalog::class)->get('free');
    $noPkr = new Plan('legacy', 'Legacy', 1500, BillingInterval::Month, [], [], null);

    expect($starter->priceLabel('USD'))->toBe('$29.00 / month')
        ->and($starter->priceLabel('PKR'))->toBe('PKR 8,000.00 / month')
        ->and($free->amountLabel('PKR'))->toBe('PKR 0.00')
        ->and($free->amountLabel('USD'))->toBe('$0')
        ->and($noPkr->priceLabel('PKR'))->toBe('$15.00 / month');
});

test('a PKR checkout charges the PKR price of the plan', function () {
    config(['billing.plans.starter.provider_price_ids.PKR' => 'price_starter_pkr']);
    $stripe = FakeStripe::install();
    $owner = teamBusiness()['owner'];

    $session = app(BillingService::class)->startCheckout($owner, 'starter', 'https://app.test/ok', 'https://app.test/cancel', 'PKR');

    $checkout = collect($stripe->requests)->first(fn ($r) => str_ends_with($r['path'], 'checkout/sessions'));
    expect($checkout['data']['line_items'][0]['price'])->toBe('price_starter_pkr')
        ->and($session->id)->not->toBeEmpty();
});

test('a plan without a PKR price refuses a PKR checkout with a clear message, not a Stripe error', function () {
    FakeStripe::install();
    $owner = teamBusiness()['owner'];

    expect(fn () => app(BillingService::class)->startCheckout($owner, 'pro', 'https://a', 'https://b', 'PKR'))
        ->toThrow(BillingException::class, "isn't available in PKR yet");
});

test('a business keeps the currency of its earlier subscription, and its plan changes stay in that currency', function () {
    config([
        'billing.plans.starter.provider_price_ids.PKR' => 'price_starter_pkr',
        'billing.plans.pro.provider_price_ids.PKR' => 'price_pro_pkr',
    ]);
    $stripe = FakeStripe::install();
    $owner = teamBusiness()['owner'];
    $billing = app(BillingService::class);

    $session = $billing->startCheckout($owner, 'starter', 'https://app.test/ok', 'https://app.test/cancel', 'PKR');
    $stripe->completeCheckout($session->id);

    $subscription = $billing->completeCheckout($owner->fresh(), $session->id);
    expect($subscription->currency)->toBe('PKR');

    $billing->changePlan($owner, 'pro');

    $update = collect($stripe->requests)->last(fn ($r) => str_contains($r['path'], 'subscriptions/'));
    expect($update['data']['items'][0]['price'])->toBe('price_pro_pkr');

    // The subscription ends; a later checkout asked for USD still uses PKR, because Stripe locks the customer.
    Subscription::query()->where('organization_id', $owner->organization_id)->update(['status' => 'cancelled']);
    $billing->startCheckout($owner->fresh(), 'starter', 'https://app.test/ok', 'https://app.test/cancel', 'USD');

    $checkout = collect($stripe->requests)->last(fn ($r) => str_ends_with($r['path'], 'checkout/sessions'));
    expect($checkout['data']['line_items'][0]['price'])->toBe('price_starter_pkr');
});

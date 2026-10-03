<?php

use App\Enums\DiscountType;
use App\Enums\EstimateStatus;
use App\Exceptions\Estimates\EstimateException;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use App\Services\Estimates\EstimateCalculator;
use App\Services\Estimates\EstimateService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->admin, $this->john, $this->conversation] = estimateBusiness();
    $this->calculator = new EstimateCalculator;
    $this->line = fn (string $quantity, string $price) => ['quantity' => Money::parse($quantity), 'unit_price' => Money::parse($price)];
});

// Creating

test('an estimate can be created as a draft with its items', function () {
    $estimate = draftEstimate($this->admin, $this->john);

    expect($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->organization_id)->toBe($this->admin->organization_id)
        ->and($estimate->customer_id)->toBe($this->john->id)
        ->and($estimate->created_by)->toBe($this->admin->id)
        ->and($estimate->currency)->toBe('USD')
        ->and($estimate->sent_at)->toBeNull()
        ->and($estimate->items()->pluck('description')->all())->toBe(['AC Installation', 'Thermostat'])
        ->and($estimate->items()->pluck('amount')->all())->toBe(['2500.00', '250.00']);
});

test('estimate numbers are generated per organization: EST-1001, EST-1002, …', function () {
    $first = draftEstimate($this->admin, $this->john);
    $second = draftEstimate($this->admin, $this->john);

    [$otherAdmin, $otherCustomer] = estimateBusiness('Houston Plumbing');
    $other = draftEstimate($otherAdmin, $otherCustomer);

    expect([$first->estimate_number, $second->estimate_number])->toBe(['EST-1001', 'EST-1002'])
        // Another organization has its own sequence.
        ->and($other->estimate_number)->toBe('EST-1001');
});

test('the estimate number is unique within an organization at the database level', function () {
    $first = draftEstimate($this->admin, $this->john);

    expect(fn () => DB::transaction(fn () => Estimate::factory()->create([
        'customer_id' => $this->john->id,
        'organization_id' => $this->admin->organization_id,
        'estimate_number' => $first->estimate_number,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('estimate numbers and totals from the browser are ignored', function () {
    $estimate = app(EstimateService::class)->create($this->admin, estimateInput($this->john, [
        'estimate_number' => 'EST-1',
        'total' => '1.00',
        'subtotal' => '1.00',
        'organization_id' => 999,
    ]));

    expect($estimate->estimate_number)->toBe('EST-1001')
        ->and($estimate->total)->toBe('2750.00')
        ->and($estimate->organization_id)->toBe($this->admin->organization_id);
});

// Money

test('item amounts are quantity × unit price, without floating point', function () {
    $totals = $this->calculator->calculate([
        ($this->line)('1', '2500'),
        ($this->line)('3', '19.99'),
        ($this->line)('1.5', '85'),
        ($this->line)('0.1', '0.15'),   // 0.015 → rounds half up to 0.02
    ]);

    expect($totals->lineAmounts)->toBe([250000, 5997, 12750, 2])
        ->and(Money::toDecimal($totals->lineAmounts[1]))->toBe('59.97');
});

test('the subtotal is the sum of the item amounts', function () {
    // 0.1 + 0.2 is exactly 0.30 here (it is not with floats).
    $totals = $this->calculator->calculate([($this->line)('1', '0.10'), ($this->line)('1', '0.20')]);

    expect($totals->subtotal)->toBe(30)
        ->and(Money::format($totals->subtotal))->toBe('$0.30');
});

test('a percentage discount is calculated on the subtotal', function () {
    $totals = $this->calculator->calculate([($this->line)('1', '2750')], DiscountType::Percent, Money::parse('10'));

    expect($totals->discount)->toBe(27500)->and($totals->total)->toBe(247500);

    // 12.5% of $99.99 = 12.49875 → $12.50
    expect($this->calculator->calculate([($this->line)('1', '99.99')], DiscountType::Percent, Money::parse('12.5'))->discount)->toBe(1250);
});

test('a fixed discount is subtracted from the subtotal', function () {
    $totals = $this->calculator->calculate([($this->line)('1', '2750')], DiscountType::Fixed, Money::parse('250'));

    expect($totals->discount)->toBe(25000)->and($totals->total)->toBe(250000);
});

test('tax is calculated on the discounted subtotal', function () {
    // $2,750 − 10% = $2,475; 8.25% tax = $204.1875 → $204.19
    $totals = $this->calculator->calculate([($this->line)('1', '2750')], DiscountType::Percent, Money::parse('10'), Money::parse('8.25', 3));

    expect($totals->tax)->toBe(20419)
        ->and($totals->total)->toBe(247500 + 20419)
        ->and(Money::format($totals->total))->toBe('$2,679.19');
});

test('the total is subtotal − discount + tax, and is stored that way', function () {
    $estimate = draftEstimate($this->admin, $this->john, ['discount_type' => 'fixed', 'discount_value' => '100', 'tax_rate' => '8.25']);

    // $2,750 − $100 = $2,650; tax 8.25% = $218.625 → $218.63; total $2,868.63
    expect($estimate->only(['subtotal', 'discount_amount', 'tax_amount', 'total']))->toBe([
        'subtotal' => '2750.00', 'discount_amount' => '100.00', 'tax_amount' => '218.63', 'total' => '2868.63',
    ])->and($estimate->tax_rate)->toBe('8.250')
        ->and($estimate->discount_type)->toBe(DiscountType::Fixed)
        ->and($estimate->money('total'))->toBe('$2,868.63');
});

test('negative amounts and totals are prevented', function (array $override, string $field) {
    try {
        draftEstimate($this->admin, $this->john, $override);
        $this->fail('Expected a validation error.');
    } catch (EstimateException $e) {
        expect($e->errors)->toHaveKey($field);
    }

    expect(Estimate::count())->toBe(0);
})->with([
    'discount larger than the subtotal' => [['discount_type' => 'fixed', 'discount_value' => '3000'], 'discount_value'],
    'discount over 100%' => [['discount_type' => 'percent', 'discount_value' => '101'], 'discount_value'],
    'negative discount' => [['discount_type' => 'fixed', 'discount_value' => '-5'], 'discount_value'],
    'negative tax rate' => [['tax_rate' => '-1'], 'tax_rate'],
    'negative price' => [['items' => [['description' => 'Credit', 'quantity' => '1', 'unit_price' => '-100']]], 'items.0.unit_price'],
    'zero quantity' => [['items' => [['description' => 'AC', 'quantity' => '0', 'unit_price' => '100']]], 'items.0.quantity'],
    'three decimals in a price' => [['items' => [['description' => 'AC', 'quantity' => '1', 'unit_price' => '1.005']]], 'items.0.unit_price'],
]);

test('the database itself rejects negative or inconsistent amounts', function () {
    $estimate = draftEstimate($this->admin, $this->john);

    expect(fn () => DB::transaction(fn () => Estimate::whereKey($estimate->id)->update(['total' => '-1.00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Estimate::whereKey($estimate->id)->update(['discount_amount' => '9999.00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => EstimateItem::where('estimate_id', $estimate->id)->update(['unit_price' => '-1.00'])))->toThrow(QueryException::class);
});

test('the database refuses impossible states such as accepted without accepted_at', function () {
    $estimate = draftEstimate($this->admin, $this->john);

    expect(fn () => DB::transaction(fn () => Estimate::whereKey($estimate->id)->update(['status' => 'accepted', 'sent_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Estimate::whereKey($estimate->id)->update(['status' => 'sent'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Estimate::whereKey($estimate->id)->update(['status' => 'paid'])))->toThrow(QueryException::class);
});

test('money is stored as numeric, not floating point', function () {
    $types = collect(DB::select("select column_name, data_type, numeric_precision, numeric_scale from information_schema.columns where table_name in ('estimates', 'estimate_items') and column_name in ('subtotal', 'discount_amount', 'tax_amount', 'total', 'unit_price', 'amount')"))
        ->map(fn ($c) => "{$c->data_type}({$c->numeric_precision},{$c->numeric_scale})")->unique()->values()->all();

    expect($types)->toBe(['numeric(12,2)']);
});

test('money parsing accepts common formats and rejects bad ones', function () {
    expect(Money::parse('2500'))->toBe(250000)
        ->and(Money::parse('2,500.5'))->toBe(250050)
        ->and(Money::parse('$2,500.00'))->toBe(250000)
        ->and(Money::parse('8.25', 3))->toBe(8250)
        ->and(Money::tryParse('abc'))->toBeNull()
        ->and(Money::tryParse('1e3'))->toBeNull()
        ->and(Money::tryParse('-1'))->toBeNull()
        ->and(Money::toDecimal(5))->toBe('0.05')
        ->and(Money::format(123456789))->toBe('$1,234,567.89');
});

test('very large amounts are rejected instead of overflowing', function () {
    $lines = array_fill(0, 50, ($this->line)('99999.99', '9999999.99'));

    expect(fn () => $this->calculator->calculate($lines, null, null, Money::parse('100', 3)))->toThrow(InvalidArgumentException::class, 'too large');
});

test('a user without an organization cannot create estimates', function () {
    $loner = User::factory()->create(['organization_id' => null]);

    expect(fn () => app(EstimateService::class)->create($loner, estimateInput($this->john)))->toThrow(EstimateException::class, 'Estimate not found.');
});

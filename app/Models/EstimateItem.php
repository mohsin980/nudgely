<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an estimate: description, quantity × unit price = amount.
 */
class EstimateItem extends Model
{
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * "1" or "1.5" rather than "1.00" / "1.50".
     */
    public function displayQuantity(): string
    {
        return rtrim(rtrim((string) $this->quantity, '0'), '.');
    }

    public function money(string $column, string $currency = 'USD'): string
    {
        return Money::format(Money::parse($this->getAttributes()[$column] ?? '0'), $currency);
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }
}

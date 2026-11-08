<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time a customer picked through the AI assistant. It holds the slot until a person declines it.
 */
class AppointmentRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DECLINED = 'declined';

    /** Requested or confirmed: these hold their time. */
    public const HOLDING = [self::STATUS_REQUESTED, self::STATUS_CONFIRMED];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One conversation the AI assistant handled: how far it got (qualified, handed over), what the lead told it,
 * and the summary for the person who takes over. Counts towards the plan's monthly AI conversations.
 */
class AssistantChat extends Model
{
    public const ACTIVE = 'active';

    public const HANDED_OVER = 'handed_over';

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'qualified_at' => 'datetime',
            'handed_over_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function takenOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_over_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Started on the business's calendar day (in its timezone). */
    public function createdToday(string $timezone): bool
    {
        return $this->created_at !== null && $this->created_at->setTimezone($timezone)->isSameDay(now($timezone));
    }
}

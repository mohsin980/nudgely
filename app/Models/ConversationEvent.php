<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a person (or an incoming message) did to a conversation, for the timeline:
 * closed, reopened, status_changed, classification_changed — and estimate activity
 * (estimate_created, estimate_sent, estimate_viewed, estimate_accepted, …), which has an
 * estimate_id and may have no conversation yet.
 */
class ConversationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(Conversation $conversation, string $type, ?User $user = null, array $data = []): self
    {
        $event = new self;
        $event->forceFill([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $user?->id,
            'type' => $type,
            'data' => $data ?: null,
        ])->save();

        return $event;
    }

    /**
     * Estimate activity, on the estimate's conversation when it has one.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordForEstimate(Estimate $estimate, string $type, ?User $user = null, array $data = []): self
    {
        $event = new self;
        $event->forceFill([
            'organization_id' => $estimate->organization_id,
            'conversation_id' => $estimate->conversation_id,
            'customer_id' => $estimate->customer_id,
            'estimate_id' => $estimate->id,
            'user_id' => $user?->id,
            'type' => $type,
            'data' => ['number' => $estimate->displayNumber()] + $data,
        ])->save();

        return $event;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

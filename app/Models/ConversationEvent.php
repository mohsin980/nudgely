<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a person (or an incoming message) did to a conversation, for the timeline:
 * closed, reopened, status_changed, classification_changed.
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

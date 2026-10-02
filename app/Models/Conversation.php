<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An email thread between an organization and one of its customers.
 *
 * organization_id and customer_id are intentionally not mass assignable.
 */
#[Fillable(['subject'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'latest_intent' => CustomerReplyIntent::class,
            'needs_attention' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<EmailReplyRoute, $this>
     */
    public function replyRoutes(): HasMany
    {
        return $this->hasMany(EmailReplyRoute::class);
    }

    /**
     * Move last_message_at forward (never backwards) and reopen the conversation.
     */
    public function recordActivity(\DateTimeInterface $at): void
    {
        $this->forceFill([
            'last_message_at' => $this->last_message_at === null || $this->last_message_at->lt($at) ? $at : $this->last_message_at,
            'status' => ConversationStatus::Open,
        ])->save();
    }
}

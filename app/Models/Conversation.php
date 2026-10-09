<?php

namespace App\Models;

use App\Enums\ConversationCloseReason;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\ReplyUrgency;
use App\Services\Dashboard\AttentionPriorityRules;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
            'latest_confidence' => 'float',
            'latest_urgency' => ReplyUrgency::class,
            'closed_at' => 'datetime',
            'closed_reason' => ConversationCloseReason::class,
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
    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * The business owes the customer something: not closed, not waiting on the customer, and either
     * marked "waiting on business", flagged for review by the AI, or the customer's latest intent
     * calls for action (ready to book, callback, question, …).
     *
     * @return array{0: string, 1: list<string>} SQL condition and bindings (shared by the scope and dashboard counts).
     */
    public static function waitingForBusinessSql(): array
    {
        $intents = array_map(fn ($intent) => $intent->value, AttentionPriorityRules::ACTION_INTENTS);
        $placeholders = implode(', ', array_fill(0, count($intents), '?'));

        return [
            "(status not in ('closed', 'waiting_customer') and (status = 'waiting_business' or needs_attention = true or latest_intent in ({$placeholders})))",
            $intents,
        ];
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeWaitingForBusiness(Builder $query): void
    {
        [$sql, $bindings] = self::waitingForBusinessSql();

        $query->whereRaw($sql, $bindings);
    }

    /**
     * A message was sent or received. A customer reply re-opens the conversation (it is now the
     * business's turn); an email from the business leaves it waiting on the customer.
     */
    public function recordActivity(\DateTimeInterface $at, ConversationStatus $status = ConversationStatus::Open): void
    {
        $reopened = $this->status === ConversationStatus::Closed && $status !== ConversationStatus::Closed;

        $this->forceFill([
            'last_message_at' => $this->last_message_at === null || $this->last_message_at->lt($at) ? $at : $this->last_message_at,
            'status' => $status,
            'closed_at' => null,
            'closed_reason' => null,
        ])->save();

        Customer::query()
            ->whereKey($this->customer_id)
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $at))
            ->update(['last_activity_at' => $at]);

        if ($reopened) {
            ConversationEvent::record($this, 'reopened', null, ['by' => 'message']);
        }
    }

    /**
     * @return HasMany<ConversationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * The most recent message in either direction (eager-loadable without N+1).
     *
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereIn('status', ['received', 'queued', 'sending', 'sent', 'failed']));
    }

    /**
     * The customer's most recent message (eager-loadable without N+1).
     *
     * @return HasOne<Message, $this>
     */
    public function latestInboundMessage(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('direction', 'inbound'));
    }

    /**
     * The most recent successful AI classification in this conversation.
     *
     * @return HasOne<MessageClassification, $this>
     */
    public function latestClassification(): HasOne
    {
        return $this->hasOne(MessageClassification::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('status', 'succeeded'));
    }

    /**
     * @return HasMany<Estimate, $this>
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }
}

<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\EstimateDeclineReason;
use App\Enums\EstimateStatus;
use App\Support\Money;
use Database\Factories\EstimateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An estimate (quote) for a customer. Amounts are numeric(12,2) decimal strings, calculated
 * only by EstimateCalculator; changes go through EstimateService.
 *
 * A sent estimate is never edited: a revision is a new Estimate with the same number and the
 * next revision, so what the customer received stays on record.
 */
class Estimate extends Model
{
    /** @use HasFactory<EstimateFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['public_token', 'public_token_hash'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EstimateStatus::class,
            'discount_type' => DiscountType::class,
            'decline_reason' => EstimateDeclineReason::class,
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'public_token' => 'encrypted',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Estimate>  $query
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('estimates.organization_id', $organization instanceof Organization ? $organization->id : $organization);
    }

    /**
     * EST-1024, or EST-1024-R2 for a revision.
     */
    public function displayNumber(): string
    {
        return $this->revision > 1 ? "{$this->estimate_number}-R{$this->revision}" : $this->estimate_number;
    }

    /**
     * An amount column in cents.
     */
    public function cents(string $column): int
    {
        return Money::parse($this->getAttributes()[$column] ?? '0');
    }

    /**
     * An amount column formatted for display, e.g. "$2,750.00".
     */
    public function money(string $column): string
    {
        return Money::format($this->cents($column), $this->currency ?? 'USD');
    }

    public function isDraft(): bool
    {
        return $this->status === EstimateStatus::Draft;
    }

    /**
     * Past its "valid until" date in the organization's timezone (whatever the stored status).
     */
    public function isPastValidUntil(Organization $organization): bool
    {
        return $this->valid_until !== null && $this->valid_until->toDateString() < $organization->localNow()->toDateString();
    }

    /**
     * The customer's link, while it has one. The token itself is stored encrypted.
     */
    public function publicUrl(): ?string
    {
        return $this->public_token ? route('estimates.public.show', $this->public_token) : null;
    }

    /**
     * The original estimate's ID for every version of it.
     */
    public function rootId(): int
    {
        return $this->revision_of_id ?? $this->id;
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
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return HasMany<EstimateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The email that carries (or carried) this estimate.
     *
     * @return BelongsTo<Message, $this>
     */
    public function sendMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'send_message_id');
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    /**
     * Activity for this estimate (it shares the conversation activity log).
     *
     * @return HasMany<ConversationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class);
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }
}

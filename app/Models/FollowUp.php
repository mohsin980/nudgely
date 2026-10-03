<?php

namespace App\Models;

use App\Enums\FollowUpCancelReason;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder to follow up with a customer, created by a person (manual) or an automation (automated).
 *
 * All columns are guarded: follow-ups are created and changed only through FollowUpService,
 * which takes the organization from the authenticated context and enforces status transitions.
 * due_at is stored in UTC; show it with Organization::localTime().
 */
class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FollowUpType::class,
            'status' => FollowUpStatus::class,
            'skip_reason' => FollowUpSkipReason::class,
            'cancelled_reason' => FollowUpCancelReason::class,
            'metadata' => 'array',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'processed_at' => 'datetime',
            'due_notified_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
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
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isAutomated(): bool
    {
        return $this->type === FollowUpType::Automated;
    }

    /**
     * An automated follow-up with an email it can send (needs a conversation for the Reply-To thread).
     */
    public function hasEmail(): bool
    {
        return filled($this->subject) && filled($this->body) && $this->conversation_id !== null;
    }

    /**
     * Short "why" for lists: the person's note, or what scheduled it.
     */
    public function reason(): string
    {
        if (filled($this->notes)) {
            return $this->notes;
        }

        return $this->metadata['reason'] ?? ($this->isAutomated() ? 'Scheduled by an automation' : 'Follow up');
    }

    /**
     * @param  Builder<FollowUp>  $query
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->id : $organization);
    }

    /**
     * @param  Builder<FollowUp>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due]);
    }
}

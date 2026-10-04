<?php

namespace App\Models;

use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One evaluation of an automation for one triggering event (audit record).
 */
class AutomationRun extends Model
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
            'event_type' => AutomationTriggerType::class,
            'status' => AutomationRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'depth' => 'integer',
            'context' => 'array',
            'condition_results' => 'array',
            'resume_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Seconds from start to finish, while known.
     */
    public function durationSeconds(): ?int
    {
        $end = $this->completed_at ?? $this->failed_at;

        return $this->started_at === null || $end === null ? null : (int) $this->started_at->diffInSeconds($end, absolute: true);
    }

    /**
     * @return HasMany<AutomationActionRun, $this>
     */
    public function actionRuns(): HasMany
    {
        return $this->hasMany(AutomationActionRun::class)->orderBy('id');
    }
}

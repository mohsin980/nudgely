<?php

namespace App\Models;

use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rule an organization defines: WHEN trigger, IF conditions, THEN actions.
 *
 * organization_id, created_by and updated_by are not mass assignable: they come from
 * the authenticated context, never from browser input.
 */
#[Fillable(['name', 'description', 'trigger_type'])]
class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'condition_match' => 'all',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AutomationStatus::class,
            'trigger_type' => AutomationTriggerType::class,
            'wait_minutes' => 'integer',
            'archived_at' => 'datetime',
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
     * @return HasMany<AutomationCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(AutomationCondition::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<AutomationAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
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
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<AutomationHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(AutomationHistory::class)->latest('id');
    }

    /**
     * "3 days", "2 hours" — or null when there is no wait.
     */
    public function waitLabel(): ?string
    {
        return self::describeWait($this->wait_minutes);
    }

    public static function describeWait(?int $minutes): ?string
    {
        if (! $minutes) {
            return null;
        }

        return match (true) {
            $minutes % 1440 === 0 => ($d = intdiv($minutes, 1440)).' '.($d === 1 ? 'day' : 'days'),
            $minutes % 60 === 0 => ($h = intdiv($minutes, 60)).' '.($h === 1 ? 'hour' : 'hours'),
            default => $minutes.' minutes',
        };
    }

    public function isActive(): bool
    {
        return $this->status === AutomationStatus::Active;
    }

    /**
     * Every automation query in tenant context must go through this scope.
     *
     * @param  Builder<Automation>  $query
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->id : $organization);
    }

    /**
     * @param  Builder<Automation>  $query
     */
    public function scopeActiveFor(Builder $query, AutomationTriggerType $trigger): void
    {
        $query->where('status', AutomationStatus::Active)->where('trigger_type', $trigger);
    }
}

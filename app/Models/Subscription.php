<?php

namespace App\Models;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's subscription as known to the billing provider. Changes only through
 * BillingService (nothing is mass assignable). The plan key refers to config/billing.php.
 */
class Subscription extends Model
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
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'scheduled_change_at' => 'datetime',
        ];
    }

    /**
     * The plan definition, or null if the plan was removed from the configuration.
     */
    public function planDefinition(): ?Plan
    {
        return app(PlanCatalog::class)->find($this->plan);
    }

    /**
     * The plan applies now: the status allows it, a trial hasn't run out, and a cancellation
     * scheduled for the period end hasn't reached it (until a provider sync marks it ended).
     */
    public function scheduledPlanDefinition(): ?Plan
    {
        return app(PlanCatalog::class)->find($this->scheduled_plan);
    }

    public function grantsAccess(): bool
    {
        if (! $this->status->grantsAccess()) {
            return false;
        }

        if ($this->status === SubscriptionStatus::Trialing && $this->trial_ends_at !== null && $this->trial_ends_at->isPast()) {
            return false;
        }

        return ! ($this->cancel_at_period_end && $this->current_period_end !== null && $this->current_period_end->isPast());
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing && $this->trial_ends_at?->isFuture();
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', SubscriptionStatus::currentValues());
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

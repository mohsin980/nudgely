<?php

namespace App\Billing;

use App\Enums\Billing\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * A subscription as the billing provider reports it, in provider-neutral terms.
 * BillingService turns it into the local Subscription row.
 */
final class ProviderSubscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $planKey,
        public readonly SubscriptionStatus $status,
        public readonly ?CarbonImmutable $trialEndsAt = null,
        public readonly ?CarbonImmutable $currentPeriodStart = null,
        public readonly ?CarbonImmutable $currentPeriodEnd = null,
        public readonly bool $cancelAtPeriodEnd = false,
        public readonly ?CarbonImmutable $canceledAt = null,
        public readonly ?CarbonImmutable $endedAt = null,
        public readonly ?string $scheduledPlanKey = null,
        public readonly ?CarbonImmutable $scheduledChangeAt = null,
        public readonly ?string $scheduleId = null,
    ) {}

    /**
     * @return array<string, mixed> Subscription columns.
     */
    public function toAttributes(): array
    {
        return [
            'provider_subscription_id' => $this->id,
            'plan' => $this->planKey,
            'status' => $this->status,
            'trial_ends_at' => $this->trialEndsAt,
            'current_period_start' => $this->currentPeriodStart,
            'current_period_end' => $this->currentPeriodEnd,
            'cancel_at_period_end' => $this->cancelAtPeriodEnd,
            'canceled_at' => $this->canceledAt,
            'ended_at' => $this->endedAt,
            'scheduled_plan' => $this->scheduledPlanKey,
            'scheduled_change_at' => $this->scheduledChangeAt,
            'provider_schedule_id' => $this->scheduleId,
        ];
    }
}

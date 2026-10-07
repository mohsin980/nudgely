<?php

namespace App\Services\Billing\Providers;

use App\Billing\Plan;
use App\Billing\ProviderSubscription;
use App\Contracts\Billing\BillingProviderInterface;
use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Keeps subscriptions locally, with no payments: used until a real provider (Stripe) is
 * connected, for internal accounts, and in tests. Behaves like a provider would.
 */
class ManualBillingProvider implements BillingProviderInterface
{
    public function name(): string
    {
        return 'manual';
    }

    public function createCustomer(Organization $organization): string
    {
        return 'manual_cus_'.Str::lower(Str::random(24));
    }

    public function createSubscription(string $customerId, Plan $plan, int $trialDays = 0): ProviderSubscription
    {
        $now = CarbonImmutable::now();
        $trialEnds = $trialDays > 0 ? $now->addDays($trialDays) : null;

        return new ProviderSubscription(
            id: 'manual_sub_'.Str::lower(Str::random(24)),
            planKey: $plan->key,
            status: $trialEnds ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            trialEndsAt: $trialEnds,
            currentPeriodStart: $now,
            currentPeriodEnd: $this->periodEnd($trialEnds ?? $now, $plan->interval),
        );
    }

    public function changePlan(Subscription $subscription, Plan $plan): ProviderSubscription
    {
        $this->assertOpen($subscription);

        return $this->snapshot($subscription, planKey: $plan->key);
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): ProviderSubscription
    {
        $this->assertOpen($subscription);
        $now = CarbonImmutable::now();

        return $atPeriodEnd
            ? $this->snapshot($subscription, cancelAtPeriodEnd: true, canceledAt: $now)
            : $this->snapshot($subscription, status: SubscriptionStatus::Cancelled, cancelAtPeriodEnd: false, canceledAt: $now, endedAt: $now);
    }

    public function resume(Subscription $subscription): ProviderSubscription
    {
        $this->assertOpen($subscription);

        return $this->snapshot($subscription, cancelAtPeriodEnd: false, canceledAt: null, clearCanceledAt: true);
    }

    private function periodEnd(CarbonImmutable $from, BillingInterval $interval): CarbonImmutable
    {
        return $interval === BillingInterval::Year ? $from->addYear() : $from->addMonth();
    }

    private function assertOpen(Subscription $subscription): void
    {
        if ($subscription->provider !== $this->name() || $subscription->status->isTerminal()) {
            throw new BillingException('This subscription can no longer be changed.');
        }
    }

    private function snapshot(Subscription $s, ?string $planKey = null, ?SubscriptionStatus $status = null, ?bool $cancelAtPeriodEnd = null,
        ?CarbonImmutable $canceledAt = null, ?CarbonImmutable $endedAt = null, bool $clearCanceledAt = false): ProviderSubscription
    {
        $immutable = fn ($value) => $value === null ? null : CarbonImmutable::instance($value);

        return new ProviderSubscription(
            id: $s->provider_subscription_id,
            planKey: $planKey ?? $s->plan,
            status: $status ?? $s->status,
            trialEndsAt: $immutable($s->trial_ends_at),
            currentPeriodStart: $immutable($s->current_period_start),
            currentPeriodEnd: $immutable($s->current_period_end),
            cancelAtPeriodEnd: $cancelAtPeriodEnd ?? $s->cancel_at_period_end,
            canceledAt: $clearCanceledAt ? null : ($canceledAt ?? $immutable($s->canceled_at)),
            endedAt: $endedAt ?? $immutable($s->ended_at),
        );
    }
}

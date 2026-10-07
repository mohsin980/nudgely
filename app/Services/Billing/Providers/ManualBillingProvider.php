<?php

namespace App\Services\Billing\Providers;

use App\Billing\CheckoutSession;
use App\Billing\CompletedCheckout;
use App\Billing\Plan;
use App\Billing\ProviderSubscription;
use App\Contracts\Billing\BillingProviderInterface;
use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keeps subscriptions locally, with no payments: for development, internal accounts and
 * tests. Behaves like a provider would: checkout "completes" straight away, downgrades wait
 * for the period end, and retrieveSubscription() rolls the period forward when it has passed.
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

    public function createCheckout(Organization $organization, string $customerId, Plan $plan, int $trialDays, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $id = 'manual_cs_'.Str::lower(Str::random(24));
        Cache::put("billing:manual-checkout:{$id}", ['organization_id' => $organization->id, 'customer_id' => $customerId, 'plan' => $plan->key, 'trial_days' => $trialDays], now()->addDay());

        return new CheckoutSession($id, str_replace('{CHECKOUT_SESSION_ID}', $id, $successUrl));
    }

    public function completedCheckout(string $sessionId): ?CompletedCheckout
    {
        $session = Cache::get("billing:manual-checkout:{$sessionId}");

        if (! is_array($session)) {
            return null;
        }

        $plan = new Plan($session['plan'], $session['plan'], 0, BillingInterval::Month, [], [], null);
        $subscription = $this->newSubscription('manual_sub_'.substr(hash('sha256', $sessionId), 0, 24), $plan, (int) $session['trial_days']);

        return new CompletedCheckout($sessionId, $session['customer_id'], $session['organization_id'], $subscription);
    }

    public function createSubscription(string $customerId, Plan $plan, int $trialDays = 0): ProviderSubscription
    {
        return $this->newSubscription('manual_sub_'.Str::lower(Str::random(24)), $plan, $trialDays);
    }

    public function changePlan(Subscription $subscription, Plan $plan): ProviderSubscription
    {
        $this->assertOpen($subscription);

        return $this->snapshot($subscription, ['planKey' => $plan->key, 'scheduledPlanKey' => null, 'scheduledChangeAt' => null, 'cancelAtPeriodEnd' => false, 'canceledAt' => null]);
    }

    public function scheduleChange(Subscription $subscription, Plan $plan, CarbonImmutable $at): ProviderSubscription
    {
        $this->assertOpen($subscription);

        return $this->snapshot($subscription, ['scheduledPlanKey' => $plan->key, 'scheduledChangeAt' => $at]);
    }

    public function cancelScheduledChange(Subscription $subscription): ProviderSubscription
    {
        $this->assertOpen($subscription);

        return $this->snapshot($subscription, ['scheduledPlanKey' => null, 'scheduledChangeAt' => null]);
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): ProviderSubscription
    {
        $this->assertOpen($subscription);
        $now = CarbonImmutable::now();

        return $atPeriodEnd
            ? $this->snapshot($subscription, ['cancelAtPeriodEnd' => true, 'canceledAt' => $now, 'scheduledPlanKey' => null, 'scheduledChangeAt' => null])
            : $this->snapshot($subscription, ['status' => SubscriptionStatus::Cancelled, 'cancelAtPeriodEnd' => false, 'canceledAt' => $now, 'endedAt' => $now, 'scheduledPlanKey' => null, 'scheduledChangeAt' => null]);
    }

    public function resume(Subscription $subscription): ProviderSubscription
    {
        $this->assertOpen($subscription);

        return $this->snapshot($subscription, ['cancelAtPeriodEnd' => false, 'canceledAt' => null]);
    }

    /**
     * Applies what would have happened at the provider by now: a trial converts, a scheduled
     * change or cancellation takes effect once its time has passed.
     */
    public function retrieveSubscription(Subscription $subscription): ProviderSubscription
    {
        $now = CarbonImmutable::now();
        $current = $this->snapshot($subscription, []);

        if ($current->status->isTerminal()) {
            return $current;
        }

        if ($current->cancelAtPeriodEnd && $current->currentPeriodEnd?->lte($now)) {
            return $this->snapshot($subscription, ['status' => SubscriptionStatus::Cancelled, 'endedAt' => $current->currentPeriodEnd]);
        }

        $changes = [];

        if ($current->status === SubscriptionStatus::Trialing && $current->trialEndsAt?->lte($now)) {
            $changes['status'] = SubscriptionStatus::Active;
        }

        if ($current->scheduledPlanKey !== null && $current->scheduledChangeAt?->lte($now)) {
            $changes += ['planKey' => $current->scheduledPlanKey, 'scheduledPlanKey' => null, 'scheduledChangeAt' => null,
                'currentPeriodStart' => $current->scheduledChangeAt, 'currentPeriodEnd' => $current->scheduledChangeAt->addMonth()];
        } elseif ($current->currentPeriodEnd?->lte($now)) {
            $changes += ['currentPeriodStart' => $current->currentPeriodEnd, 'currentPeriodEnd' => $current->currentPeriodEnd->addMonth()];
        }

        return $changes === [] ? $current : $this->snapshot($subscription, $changes);
    }

    public function fetchSubscription(string $providerSubscriptionId): ProviderSubscription
    {
        $subscription = Subscription::query()->where('provider', $this->name())->where('provider_subscription_id', $providerSubscriptionId)->first()
            ?? throw new BillingException('Unknown subscription.');

        return $this->retrieveSubscription($subscription);
    }

    /**
     * No hosted portal locally: back to the billing page.
     */
    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        return $returnUrl;
    }

    private function newSubscription(string $id, Plan $plan, int $trialDays): ProviderSubscription
    {
        $now = CarbonImmutable::now();
        $trialEnds = $trialDays > 0 ? $now->addDays($trialDays) : null;

        return new ProviderSubscription(
            id: $id,
            planKey: $plan->key,
            status: $trialEnds ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            trialEndsAt: $trialEnds,
            currentPeriodStart: $now,
            currentPeriodEnd: $trialEnds ?? ($plan->interval === BillingInterval::Year ? $now->addYear() : $now->addMonth()),
        );
    }

    private function assertOpen(Subscription $subscription): void
    {
        if ($subscription->provider !== $this->name() || $subscription->status->isTerminal()) {
            throw new BillingException('This subscription can no longer be changed.');
        }
    }

    /**
     * The stored subscription with some fields replaced.
     *
     * @param  array<string, mixed>  $changes  ProviderSubscription constructor arguments
     */
    private function snapshot(Subscription $s, array $changes): ProviderSubscription
    {
        $immutable = fn ($value) => $value === null ? null : CarbonImmutable::instance($value);

        return new ProviderSubscription(...array_merge([
            'id' => $s->provider_subscription_id,
            'planKey' => $s->plan,
            'status' => $s->status,
            'trialEndsAt' => $immutable($s->trial_ends_at),
            'currentPeriodStart' => $immutable($s->current_period_start),
            'currentPeriodEnd' => $immutable($s->current_period_end),
            'cancelAtPeriodEnd' => $s->cancel_at_period_end,
            'canceledAt' => $immutable($s->canceled_at),
            'endedAt' => $immutable($s->ended_at),
            'scheduledPlanKey' => $s->scheduled_plan,
            'scheduledChangeAt' => $immutable($s->scheduled_change_at),
            'scheduleId' => $s->provider_schedule_id,
        ], $changes));
    }
}

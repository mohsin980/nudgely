<?php

namespace App\Services\Billing;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Exceptions\Billing\PlanLimitException;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * What an organization may use right now. The rest of the app asks this service (never the
 * billing provider): the plan of a subscription that grants access, otherwise the default (free) plan.
 */
class EntitlementService
{
    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly UsageService $usage,
    ) {}

    public function plan(Organization $organization): Plan
    {
        $subscription = $this->subscription($organization);

        // A paid subscription first; otherwise the sign-up trial while it lasts; otherwise Free.
        return ($subscription?->grantsAccess() ? $subscription->planDefinition() : null)
            ?? $this->trial($organization)['plan']
            ?? $this->plans->default();
    }

    /**
     * The card-free sign-up trial, while it is running (null otherwise).
     *
     * @return array{plan: Plan, ends_at: CarbonInterface, days_left: int}|null
     */
    public function trial(Organization $organization): ?array
    {
        $plan = $organization->trial_ends_at?->isFuture() ? $this->plans->find(config('billing.signup_trial_plan')) : null;

        return $plan === null ? null : ['plan' => $plan, 'ends_at' => $organization->trial_ends_at, 'days_left' => max(1, (int) ceil(now()->floatDiffInDays($organization->trial_ends_at)))];
    }

    public function subscription(Organization $organization): ?Subscription
    {
        return Subscription::query()->where('organization_id', $organization->id)->current()->latest('id')->first();
    }

    /**
     * The limit, or null when unlimited.
     */
    public function limit(Organization $organization, LimitKey $key): ?int
    {
        return $this->plan($organization)->limit($key);
    }

    public function hasFeature(Organization $organization, string $feature): bool
    {
        return $this->plan($organization)->hasFeature($feature);
    }

    /**
     * May the organization add $amount more (e.g. one more customer)?
     */
    public function allows(Organization $organization, LimitKey $key, int $amount = 1): bool
    {
        $limit = $this->limit($organization, $key);

        return $limit === null || $this->usage->usage($organization, $key) + $amount <= $limit;
    }

    /**
     * Stop (with a message safe to show) when the plan doesn't allow $amount more. Nothing existing
     * is ever removed: only adding is refused. $reserved counts things already promised
     * (e.g. open invitations for team seats).
     *
     * @throws PlanLimitException
     */
    public function assertAllows(Organization $organization, LimitKey $key, int $amount = 1, int $reserved = 0): void
    {
        if (! config('billing.enforce_limits')) {
            return;
        }

        $plan = $this->plan($organization);
        $limit = $plan->limit($key);

        if ($limit !== null && $this->usage->usage($organization, $key) + $reserved + $amount > $limit) {
            throw new PlanLimitException($key, $limit, $plan->name, $this->upgradeFor($plan, $key, $limit));
        }
    }

    /**
     * Check the limit and create the thing in one step that concurrent requests take turns in:
     * the organization row is locked until the transaction ends, so two requests at 99/100 can't
     * both pass the check and create number 101. $callback runs inside the transaction and must
     * do the creating there.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  int|Closure(): int  $reserved  things already promised, counted inside the lock
     * @return T
     *
     * @throws PlanLimitException
     */
    public function guard(Organization $organization, LimitKey $key, Closure $callback, int|Closure $reserved = 0): mixed
    {
        return DB::transaction(function () use ($organization, $key, $callback, $reserved) {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->value('id');
            $this->assertAllows($organization, $key, 1, $reserved instanceof Closure ? $reserved() : $reserved);

            return $callback();
        });
    }

    // Centralized "may I?" questions: the only place callers (services, pages) ask, never Blade.

    public function canCreateCustomer(Organization $organization): bool
    {
        return $this->within($organization, LimitKey::Customers);
    }

    /**
     * @param  int  $reserved  seats already promised, e.g. open invitations
     */
    public function canAddTeamMember(Organization $organization, int $reserved = 0): bool
    {
        return $this->within($organization, LimitKey::TeamMembers, $reserved);
    }

    public function canCreateAutomation(Organization $organization): bool
    {
        return $this->within($organization, LimitKey::Automations);
    }

    public function canSendEmail(Organization $organization): bool
    {
        return $this->within($organization, LimitKey::OutboundEmails);
    }

    public function canCreateEstimate(Organization $organization): bool
    {
        return $this->within($organization, LimitKey::Estimates);
    }

    private function within(Organization $organization, LimitKey $key, int $reserved = 0): bool
    {
        try {
            $this->assertAllows($organization, $key, 1, $reserved);

            return true;
        } catch (PlanLimitException) {
            return false;
        }
    }

    /**
     * The cheapest plan that lifts this limit (null if none does).
     */
    private function upgradeFor(Plan $current, LimitKey $key, int $limit): ?Plan
    {
        $better = array_filter($this->plans->all(), fn (Plan $plan) => $plan->priceCents > $current->priceCents && ($plan->limit($key) === null || $plan->limit($key) > $limit));
        usort($better, fn (Plan $a, Plan $b) => $a->priceCents <=> $b->priceCents);

        return $better[0] ?? null;
    }

    /**
     * How many more are allowed (null = unlimited), never below zero.
     */
    public function remaining(Organization $organization, LimitKey $key): ?int
    {
        $limit = $this->limit($organization, $key);

        return $limit === null ? null : max(0, $limit - $this->usage->usage($organization, $key));
    }

    /**
     * Every limit with its usage, for the billing page.
     *
     * @return array<string, array{label: string, used: int, limit: int|null, remaining: int|null, over: bool}>
     */
    public function summary(Organization $organization): array
    {
        $plan = $this->plan($organization);
        $rows = [];

        foreach (LimitKey::cases() as $key) {
            $used = $this->usage->usage($organization, $key);
            $limit = $plan->limit($key);
            $rows[$key->value] = ['label' => $key->label(), 'used' => $used, 'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used), 'over' => $limit !== null && $used > $limit];
        }

        return $rows;
    }
}

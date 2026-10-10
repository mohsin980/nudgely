<?php

namespace App\Support\Admin;

use App\Billing\PlanCatalog;
use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Team\MemberStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Platform-wide numbers for the Super Admin dashboard. Each method is one or two aggregate queries
 * (no per-organization loops). Definitions are documented in docs/admin-dashboard.md.
 */
class PlatformMetrics
{
    /** An organization is "active" when one of its active members used the app within this many days. */
    public const ACTIVE_DAYS = 30;

    /** Length of the comparison windows ("last 30 days" vs the 30 before). */
    public const WINDOW_DAYS = 30;

    /** @var array<string, int>|null */
    private ?array $subscriptionCounts = null;

    public function __construct(private readonly PlanCatalog $plans) {}

    /**
     * @return array{total: int, active: int, new: int, previous: int}
     */
    public function organizations(): array
    {
        $since = $this->now()->subDays(self::ACTIVE_DAYS);

        return [
            'total' => Organization::query()->count(),
            'active' => User::query()->whereNotNull('organization_id')->where('status', MemberStatus::Active)
                ->where('last_active_at', '>=', $since)->distinct()->count('organization_id'),
            ...$this->createdComparison(Organization::query()),
        ];
    }

    /**
     * @return array{total: int, active: int, new: int, previous: int}
     */
    public function users(): array
    {
        return [
            'total' => User::query()->count(),
            'active' => User::query()->where('status', MemberStatus::Active)->count(),
            ...$this->createdComparison(User::query()),
        ];
    }

    /**
     * Subscription records by status. "Active" is the Active status only: trials, past due, paused, incomplete
     * and unpaid are counted separately and never as active.
     *
     * @return array{active: int, trialing: int, past_due: int, cancelled: int, cancelled_recent: int, cancelled_previous: int}
     */
    public function subscriptions(): array
    {
        $counts = $this->statusCounts();
        $now = $this->now();
        $window = self::WINDOW_DAYS;

        $cancelled = Subscription::query()->where('status', SubscriptionStatus::Cancelled)
            ->selectRaw('count(case when canceled_at >= ? then 1 end) as recent', [$now->subDays($window)])
            ->selectRaw('count(case when canceled_at >= ? and canceled_at < ? then 1 end) as previous', [$now->subDays($window * 2), $now->subDays($window)])
            ->first();

        return [
            'active' => $counts[SubscriptionStatus::Active->value] ?? 0,
            'trialing' => $counts[SubscriptionStatus::Trialing->value] ?? 0,
            'past_due' => $counts[SubscriptionStatus::PastDue->value] ?? 0,
            'cancelled' => $counts[SubscriptionStatus::Cancelled->value] ?? 0,
            'cancelled_recent' => (int) $cancelled->recent,
            'cancelled_previous' => (int) $cancelled->previous,
        ];
    }

    /**
     * Monthly recurring revenue, calculated locally from subscriptions and the plan prices in config/billing.php.
     * It is a list-price estimate, not what Stripe collected: discounts, tax, refunds and failed payments are
     * not reflected. Counts Active and Past due subscriptions of a real payment provider; trials, paused,
     * incomplete, unpaid, cancelled and "manual" (no payments) subscriptions are excluded. Yearly plans count 1/12.
     *
     * @return array{available: bool, cents: int, currency: string, subscriptions: int, unknown_plan: int, at_risk_cents: int, reason: ?string}
     */
    public function mrr(): array
    {
        $currency = (string) config('billing.currency', 'USD');
        $unavailable = fn (string $reason) => ['available' => false, 'cents' => 0, 'currency' => $currency, 'subscriptions' => 0, 'unknown_plan' => 0, 'at_risk_cents' => 0, 'reason' => $reason];

        if (config('billing.provider') === 'manual' && ! Subscription::query()->where('provider', '!=', 'manual')->exists()) {
            return $unavailable('Billing is in manual mode (no payment provider is connected), so there is no real recurring revenue to measure.');
        }

        $rows = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->where('provider', '!=', 'manual')
            ->selectRaw('plan, status, count(*) as total')
            ->groupBy('plan', 'status')
            ->get();

        $cents = 0;
        $atRisk = 0;
        $counted = 0;
        $unknown = 0;

        foreach ($rows as $row) {
            $plan = $this->plans->find($row->plan);

            if ($plan === null) {
                $unknown += (int) $row->total;

                continue;
            }

            $monthly = (int) round(($plan->interval === BillingInterval::Year ? $plan->priceCents / 12 : $plan->priceCents) * (int) $row->total);
            $cents += $monthly;
            $counted += (int) $row->total;

            if ($row->status === SubscriptionStatus::PastDue) {
                $atRisk += $monthly;
            }
        }

        return ['available' => true, 'cents' => $cents, 'currency' => $currency, 'subscriptions' => $counted, 'unknown_plan' => $unknown, 'at_risk_cents' => $atRisk, 'reason' => null];
    }

    /**
     * @return array<string, int> status value => count
     */
    private function statusCounts(): array
    {
        return $this->subscriptionCounts ??= Subscription::query()
            ->selectRaw('status, count(*) as total')->groupBy('status')->get()
            ->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->total])->all();
    }

    /**
     * New records in the last window and in the window before it, in one query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return array{new: int, previous: int}
     */
    private function createdComparison($query): array
    {
        $now = $this->now();
        $window = self::WINDOW_DAYS;

        $row = $query
            ->selectRaw('count(case when created_at >= ? then 1 end) as recent', [$now->subDays($window)])
            ->selectRaw('count(case when created_at >= ? and created_at < ? then 1 end) as previous', [$now->subDays($window * 2), $now->subDays($window)])
            ->first();

        return ['new' => (int) $row->recent, 'previous' => (int) $row->previous];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}

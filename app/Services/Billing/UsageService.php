<?php

namespace App\Services\Billing;

use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Team\MemberStatus;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How much of each plan limit an organization uses, counted from the records themselves
 * (no separate counters to drift). Monthly limits follow the organization's billing period
 * (the subscription's current period); without a subscription they use the calendar month in the
 * organization's timezone.
 */
class UsageService
{
    public function usage(Organization $organization, LimitKey $key): int
    {
        [$start, $end] = $this->period($organization);

        return match ($key) {
            // Sample customers from onboarding are demo data, not part of the plan's customer count.
            LimitKey::Customers => Customer::query()->where('organization_id', $organization->id)->where('is_demo', false)->count(),
            // Only automations that run use a slot; drafts, paused and archived ones don't.
            LimitKey::Automations => Automation::query()->where('organization_id', $organization->id)->where('status', AutomationStatus::Active)->count(),
            // Seats: active members. (Open invitations are added by the caller; suspended people need a seat again to come back.)
            LimitKey::TeamMembers => User::query()->where('organization_id', $organization->id)->where('status', MemberStatus::Active)->count(),
            // Emails that went out or are on their way; failed ones never reached the customer, so they are free.
            LimitKey::OutboundEmails => Message::query()->where('organization_id', $organization->id)->where('direction', MessageDirection::Outbound)
                ->whereIn('status', array_map(fn (MessageStatus $status) => $status->value, array_filter(MessageStatus::cases(), fn (MessageStatus $status) => $status->countsAsSent())))
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
            // New estimates only: a revision of a sent estimate isn't a new estimate.
            LimitKey::Estimates => Estimate::query()->where('organization_id', $organization->id)->where('revision', 1)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
        };
    }

    /**
     * @return array<string, int> LimitKey value => usage
     */
    public function all(Organization $organization): array
    {
        $usage = [];

        foreach (LimitKey::cases() as $key) {
            $usage[$key->value] = $this->usage($organization, $key);
        }

        return $usage;
    }

    /**
     * The period monthly limits count in, as [start, end) in UTC: the subscription's current billing
     * period (rolled forward by whole months if the provider hasn't reported the renewal yet),
     * or the calendar month in the business's timezone when there is no subscription period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(Organization $organization): array
    {
        return $this->periodFor($organization, Subscription::query()->where('organization_id', $organization->id)->current()->latest('id')->first());
    }

    /**
     * period() for callers that already hold the organization's current subscription.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodFor(Organization $organization, ?Subscription $subscription): array
    {
        if ($subscription?->grantsAccess() && $subscription->current_period_start !== null && $subscription->current_period_end !== null) {
            $start = CarbonImmutable::instance($subscription->current_period_start)->utc();
            $end = CarbonImmutable::instance($subscription->current_period_end)->utc();

            for ($i = 0; $i < 120 && $end->lte(now()); $i++) {
                [$start, $end] = [$end, $end->addMonthNoOverflow()];
            }

            if ($end->gt($start)) {
                return [$start, $end];
            }
        }

        $start = $organization->localNow()->startOfMonth();

        return [$start->utc(), $start->addMonth()->utc()];
    }

    /**
     * usage() for many organizations at once, in a fixed number of queries per 100 organizations instead of five per
     * organization. Same definitions and the same periods as usage()/period(); a test keeps them identical.
     *
     * @param  iterable<Organization>  $organizations
     * @param  array<int, Subscription|null>  $subscriptions  current subscription by organization ID (as loaded by currentSubscriptions())
     * @return array<int, array<string, int>> organization ID => LimitKey value => usage
     */
    public function usageForMany(iterable $organizations, array $subscriptions): array
    {
        $result = [];

        foreach (collect($organizations)->chunk(100) as $chunk) {
            $ids = $chunk->pluck('id')->all();
            $periods = $chunk->mapWithKeys(fn (Organization $o) => [$o->id => $this->periodFor($o, $subscriptions[$o->id] ?? null)]);

            $customers = Customer::query()->whereIn('organization_id', $ids)->where('is_demo', false)->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');
            $automations = Automation::query()->whereIn('organization_id', $ids)->where('status', AutomationStatus::Active)->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');
            $members = User::query()->whereIn('organization_id', $ids)->where('status', MemberStatus::Active)->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');

            $sentStatuses = array_map(fn (MessageStatus $status) => $status->value, array_filter(MessageStatus::cases(), fn (MessageStatus $status) => $status->countsAsSent()));
            $emails = $this->inPeriods(Message::query()->where('direction', MessageDirection::Outbound)->whereIn('status', $sentStatuses), $periods)->pluck('total', 'organization_id');
            $estimates = $this->inPeriods(Estimate::query()->where('revision', 1), $periods)->pluck('total', 'organization_id');

            foreach ($ids as $id) {
                $result[$id] = [
                    LimitKey::Customers->value => (int) ($customers[$id] ?? 0),
                    LimitKey::Automations->value => (int) ($automations[$id] ?? 0),
                    LimitKey::TeamMembers->value => (int) ($members[$id] ?? 0),
                    LimitKey::OutboundEmails->value => (int) ($emails[$id] ?? 0),
                    LimitKey::Estimates->value => (int) ($estimates[$id] ?? 0),
                ];
            }
        }

        return $result;
    }

    /**
     * Current subscription of each organization (the latest non-finished one, as period()/EntitlementService use).
     *
     * @param  list<int>  $organizationIds
     * @return array<int, Subscription>
     */
    public function currentSubscriptions(array $organizationIds): array
    {
        $subscriptions = [];

        foreach (array_chunk($organizationIds, 500) as $ids) {
            foreach (Subscription::query()->whereIn('organization_id', $ids)->current()->orderBy('id')->get() as $subscription) {
                $subscriptions[$subscription->organization_id] = $subscription; // the later id wins, as latest('id') does
            }
        }

        return $subscriptions;
    }

    /**
     * Rows created inside each organization's own [start, end) period, counted per organization.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $periods
     */
    private function inPeriods($query, $periods)
    {
        return $query->where(function ($q) use ($periods) {
            foreach ($periods as $id => [$start, $end]) {
                $q->orWhere(fn ($inner) => $inner->where('organization_id', $id)->where('created_at', '>=', $start)->where('created_at', '<', $end));
            }
        })->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->get();
    }
}

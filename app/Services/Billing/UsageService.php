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
        $subscription = Subscription::query()->where('organization_id', $organization->id)->current()->latest('id')->first();

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
}

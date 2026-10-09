<?php

namespace App\Services\Billing;

use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\MessageDirection;
use App\Enums\Team\MemberStatus;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * How much of each plan limit an organization uses, counted from the records themselves
 * (no separate counters to drift). Monthly limits use the calendar month in the
 * organization's timezone.
 */
class UsageService
{
    public function usage(Organization $organization, LimitKey $key): int
    {
        [$start, $end] = $this->period($organization);

        return match ($key) {
            LimitKey::Customers => Customer::query()->where('organization_id', $organization->id)->count(),
            // Archived automations never run, so they don't use a slot.
            LimitKey::Automations => Automation::query()->where('organization_id', $organization->id)->where('status', '!=', AutomationStatus::Archived)->count(),
            // Seats: everyone who can be reactivated (active or suspended), not people who were removed.
            LimitKey::TeamMembers => User::query()->where('organization_id', $organization->id)->where('status', '!=', MemberStatus::Removed)->count(),
            LimitKey::OutboundEmails => Message::query()->where('organization_id', $organization->id)->where('direction', MessageDirection::Outbound)
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
     * The current monthly usage period, in UTC: [start of this month, start of next month] in the business's timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(Organization $organization): array
    {
        $start = $organization->localNow()->startOfMonth();

        return [$start->utc(), $start->addMonth()->utc()];
    }
}

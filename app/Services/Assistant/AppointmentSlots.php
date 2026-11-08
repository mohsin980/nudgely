<?php

namespace App\Services\Assistant;

use App\Models\AppointmentRequest;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The appointment times the AI assistant may offer: free one-hour slots inside the business's opening hours, in the
 * business's timezone, starting at least an hour from now. Times already requested or confirmed are not offered.
 */
class AppointmentSlots
{
    public const LENGTH_MINUTES = 60;

    public const LEAD_MINUTES = 60;

    public const DAYS_AHEAD = 5;

    public const MAX_OFFERED = 6;

    /**
     * @return list<array{key: string, label: string, at: CarbonImmutable}> key is the local start time, e.g. 2026-11-10T09:00
     */
    public function offered(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $timezone = $organization->timezone();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $hours = $organization->businessSettings()->businessHours()->toArray();
        $earliest = $now->addMinutes(self::LEAD_MINUTES);
        $taken = $this->takenTimes($organization, $now)->flip();
        $slots = [];

        for ($day = 0; $day <= self::DAYS_AHEAD && count($slots) < self::MAX_OFFERED; $day++) {
            $date = $now->startOfDay()->addDays($day);
            $setting = $hours[strtolower($date->format('D'))] ?? null;

            if (($setting['open'] ?? false) !== true) {
                continue;
            }

            $closes = $date->setTimeFromTimeString($setting['end']);

            for ($start = $date->setTimeFromTimeString($setting['start']); $start->addMinutes(self::LENGTH_MINUTES)->lessThanOrEqualTo($closes); $start = $start->addMinutes(self::LENGTH_MINUTES)) {
                if ($start->lessThan($earliest) || $taken->has($start->getTimestamp())) {
                    continue;
                }

                $slots[] = ['key' => $start->format('Y-m-d\TH:i'), 'label' => $this->label($organization, $start), 'at' => $start];

                if (count($slots) >= self::MAX_OFFERED) {
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * The offered slot with this key, or null (a key the assistant made up, or one no longer free).
     *
     * @return array{key: string, label: string, at: CarbonImmutable}|null
     */
    public function find(Organization $organization, string $key, ?CarbonImmutable $now = null): ?array
    {
        return collect($this->offered($organization, $now))->firstWhere('key', $key);
    }

    /** "Mon 10 Nov, 9:00 AM", in the business's timezone and time format. */
    public function label(Organization $organization, \DateTimeInterface $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($organization->timezone());

        return $local->format('D j M, ').$organization->formatTime($local);
    }

    /**
     * @return Collection<int, int> start times (unix seconds) already held
     */
    private function takenTimes(Organization $organization, CarbonImmutable $now)
    {
        return AppointmentRequest::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', AppointmentRequest::HOLDING)
            ->where('starts_at', '>=', $now->subDay()->utc())
            ->pluck('starts_at')
            ->map(fn ($at) => $at->getTimestamp())
            ->values();
    }
}

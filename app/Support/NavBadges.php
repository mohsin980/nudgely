<?php

namespace App\Support;

use App\Models\Organization;
use App\Services\Dashboard\DashboardService;
use Illuminate\Support\Facades\Cache;

/**
 * The two numbers shown as red dots in the sidebar: messages waiting for a reply and overdue reminders.
 * Reuses the dashboard's own counts so the dots always match the dashboard, and caches them briefly
 * because the sidebar renders on every page.
 */
class NavBadges
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * @return array{messages: int, reminders: int}
     */
    public function for(Organization $organization): array
    {
        return Cache::remember("nav-badges:{$organization->id}", 30, function () use ($organization) {
            [$start, $end] = $this->dashboard->today($organization);

            return [
                'messages' => $this->dashboard->conversationStatusCounts($organization)['waiting'],
                'reminders' => $this->dashboard->followUpCounts($organization, $start, $end)['overdue'],
            ];
        });
    }
}

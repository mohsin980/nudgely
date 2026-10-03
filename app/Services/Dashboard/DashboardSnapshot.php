<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the dashboard renders, computed in one pass by DashboardService.
 */
final readonly class DashboardSnapshot
{
    /**
     * @param  array{overdue: int, due_today: int, waiting: int, new_replies: int}  $summary
     * @param  array{new_customers: int, customer_replies: int, follow_ups_completed: int, follow_ups_due: int, emails_sent: int}  $today
     * @param  array<string, int>  $conversationStatuses
     * @param  array{open: int, due: int, overdue: int, later: int}  $taskCounts
     */
    public function __construct(
        public CarbonImmutable $localNow,
        public array $summary,
        public array $today,
        public array $conversationStatuses,
        public bool $hasCustomers,
        public bool $hasOpenFollowUps,
        public Collection $attention,
        public Collection $overdueFollowUps,
        public Collection $todaysFollowUps,
        public Collection $tasks,
        public array $taskCounts,
        public Collection $recentReplies,
        public Collection $automationRuns,
        public Collection $notifications,
        public int $unreadNotifications,
    ) {}
}

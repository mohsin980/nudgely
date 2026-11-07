<?php

namespace App\Livewire;

use App\Enums\LeadStage;
use App\Models\AssistantChat;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Billing\UsageService;
use App\Services\Dashboard\DashboardService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "What needs my attention today?" — the business's daily action center.
 *
 * All data comes from DashboardService for the signed-in user's organization. The page loads
 * lazily behind a skeleton and refreshes itself every minute while visible (no WebSockets).
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
#[Lazy]
class Dashboard extends Component
{
    public bool $showAddCustomer = false;

    public function mount(): void
    {
        // Only members of an organization have a dashboard.
        abort_if(Auth::user()->organization_id === null, 403);
    }

    public function markNotificationRead(string $notificationId): void
    {
        Auth::user()->unreadNotifications()->whereKey($notificationId)->update(['read_at' => now()]);
    }

    public function markAllNotificationsRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function completeTask(int $taskId, TaskService $tasks): void
    {
        // Only a task in the user's own organization; TaskService checks again.
        $task = Task::query()->where('organization_id', Auth::user()->organization_id)->whereKey($taskId)->first() ?? abort(404);

        $tasks->complete(Auth::user(), $task);
    }

    public function placeholder()
    {
        return view('livewire.dashboard-placeholder');
    }

    public function render(DashboardService $dashboard)
    {
        $user = Auth::user();
        $snapshot = $dashboard->snapshot($user);
        $hour = $snapshot->localNow->hour;

        return view('livewire.dashboard', [
            'd' => $snapshot,
            'organization' => $user->organization,
            'checklist' => app(OnboardingService::class)->checklist($user),
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 17 => 'Good afternoon',
                default => 'Good evening',
            },
            'firstName' => strtok(trim($user->name), ' ') ?: $user->name,
            'assistant' => $this->assistantStats($user->organization),
        ]);
    }

    /**
     * This billing period's AI assistant numbers, and leads waiting for a Won / Lost decision. Null when the
     * business never switched the assistant on.
     *
     * @return array{name: string, on: bool, chats: int, qualified: int, handed_over: int, awaiting_outcome: int}|null
     */
    private function assistantStats(Organization $organization): ?array
    {
        $profile = $organization->assistantProfile()->first();

        if ($profile === null || (! $profile->enabled && ! AssistantChat::query()->where('organization_id', $organization->id)->exists())) {
            return null;
        }

        [$start, $end] = app(UsageService::class)->period($organization);
        $chats = AssistantChat::query()->where('organization_id', $organization->id)->where('created_at', '>=', $start)->where('created_at', '<', $end);

        return [
            'name' => $profile->assistant_name ?: 'AI assistant',
            'on' => (bool) $profile->enabled,
            'chats' => (clone $chats)->count(),
            'qualified' => (clone $chats)->whereNotNull('qualified_at')->count(),
            'handed_over' => (clone $chats)->where('status', AssistantChat::HANDED_OVER)->count(),
            'awaiting_outcome' => $organization->customers()->whereIn('lead_stage', [LeadStage::Qualified, LeadStage::Quoted])
                ->where('lead_stage_changed_at', '<=', now()->subDays((int) config('leads.outcome_reminders.after_days', 7)))->count(),
        ];
    }
}

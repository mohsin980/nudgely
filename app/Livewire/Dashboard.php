<?php

namespace App\Livewire;

use App\Services\Dashboard\DashboardService;
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
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 17 => 'Good afternoon',
                default => 'Good evening',
            },
            'firstName' => strtok(trim($user->name), ' ') ?: $user->name,
        ]);
    }
}

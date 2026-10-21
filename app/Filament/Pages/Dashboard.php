<?php

namespace App\Filament\Pages;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Landing page of the Super Admin panel. Platform metrics are added in later tasks.
 */
class Dashboard extends BaseDashboard
{
    use RequiresPlatformPermission;

    protected static ?string $title = 'Platform overview';

    protected static ?string $navigationLabel = 'Overview';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewDashboard;
    }

    /**
     * One column: each metric group takes the full width, so the rows line up on wide screens.
     */
    public function getColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Platform health at a glance: customers, users, subscriptions and recent activity.';
    }
}

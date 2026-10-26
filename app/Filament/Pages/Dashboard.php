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

    protected static ?string $title = 'Platform Overview';

    protected static ?string $navigationLabel = 'Overview';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewDashboard;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Monitor your platform health, customer activity, and key metrics across '.config('admin.brand').'.';
    }

    /**
     * Three columns on wide screens, two on tablets, one on phones.
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 3];
    }
}

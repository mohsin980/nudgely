<?php

namespace App\Filament\Pages;

use App\Enums\Platform\PlatformPermission;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Landing page of the Super Admin panel. Platform metrics are added in later tasks.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Platform overview';

    protected static ?string $navigationLabel = 'Overview';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PlatformPermission::ViewDashboard->value) ?? false;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return config('admin.brand').' platform administration. Modules are added here as they are built.';
    }
}

<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Landing page of the Super Admin panel. Platform metrics are added in later tasks.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Platform overview';

    protected static ?string $navigationLabel = 'Overview';

    public function getSubheading(): string|Htmlable|null
    {
        return config('admin.brand').' platform administration. Modules are added here as they are built.';
    }
}

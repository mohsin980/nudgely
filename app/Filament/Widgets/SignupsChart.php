<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\Organization;
use Filament\Widgets\ChartWidget;

/**
 * New businesses per month for the last six months, counted from organizations.created_at. Six small counts, so the
 * query cost does not grow with the number of businesses.
 */
class SignupsChart extends ChartWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 25;

    protected ?string $heading = 'New businesses per month';

    protected ?string $maxHeight = '220px';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOrganizations;
    }

    protected function getData(): array
    {
        $labels = [];
        $counts = [];

        for ($month = 5; $month >= 0; $month--) {
            $start = now()->startOfMonth()->subMonths($month);
            $labels[] = $start->format('M Y');
            $counts[] = Organization::query()
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $start->copy()->addMonth())
                ->count();
        }

        return [
            'datasets' => [['label' => 'New businesses', 'data' => $counts, 'backgroundColor' => '#7c3aed']],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}

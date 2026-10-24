<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\ReportsPlatformMetrics;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Support\Admin\PlatformMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrganizationStats extends StatsOverviewWidget
{
    use ReportsPlatformMetrics;
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 10;

    protected ?string $heading = 'Customers';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOrganizations;
    }

    protected function getStats(): array
    {
        return $this->safely(function () {
            $metrics = app(PlatformMetrics::class);
            $orgs = $metrics->organizations();
            $days = PlatformMetrics::ACTIVE_DAYS;

            return [
                Stat::make('Organizations', number_format($orgs['total']))
                    ->description($this->comparison($orgs['new'], $orgs['previous'], 'new')),
                Stat::make('Active organizations', number_format($orgs['active']))
                    ->description("Had an active member use the app in the last {$days} days"),
            ];
        }, 'Organizations');
    }
}

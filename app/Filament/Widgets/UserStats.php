<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\ReportsPlatformMetrics;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Support\Admin\PlatformMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserStats extends StatsOverviewWidget
{
    use ReportsPlatformMetrics;
    use RequiresPlatformPermission;

    protected static ?int $sort = 20;

    protected ?string $heading = 'Users';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewUsers;
    }

    protected function getStats(): array
    {
        return $this->safely(function () {
            $users = app(PlatformMetrics::class)->users();

            return [
                Stat::make('Registered users', number_format($users['total']))
                    ->description($this->comparison($users['new'], $users['previous'], 'new')),
                Stat::make('Active accounts', number_format($users['active']))
                    ->description('Not suspended or removed'),
            ];
        }, 'Users');
    }
}

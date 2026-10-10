<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\ReportsPlatformMetrics;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Support\Admin\PlatformMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SubscriptionStats extends StatsOverviewWidget
{
    use ReportsPlatformMetrics;
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 30;

    protected ?string $heading = 'Subscriptions';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewSubscriptions;
    }

    protected function getStats(): array
    {
        return $this->safely(function () {
            $subs = app(PlatformMetrics::class)->subscriptions();

            return [
                Stat::make('Active subscriptions', number_format($subs['active']))
                    ->description($subs['past_due'] > 0 ? "{$subs['past_due']} more past due (payment failing)" : 'Paying, not past due'),
                Stat::make('Trialing', number_format($subs['trialing']))
                    ->description('Free trial in progress'),
                Stat::make('Canceled', number_format($subs['cancelled']))
                    ->description($this->comparison($subs['cancelled_recent'], $subs['cancelled_previous'], 'canceled')),
            ];
        }, 'Subscriptions');
    }
}

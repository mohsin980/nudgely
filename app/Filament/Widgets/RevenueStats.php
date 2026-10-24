<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\ReportsPlatformMetrics;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Support\Admin\PlatformMetrics;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueStats extends StatsOverviewWidget
{
    use ReportsPlatformMetrics;
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 40;

    protected ?string $heading = 'Revenue';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewPayments;
    }

    protected function getStats(): array
    {
        return $this->safely(function () {
            $mrr = app(PlatformMetrics::class)->mrr();

            if (! $mrr['available']) {
                $first = Stat::make('MRR (calculated locally)', 'Not available')->description($mrr['reason'])->color('gray');
            } else {
                $note = $mrr['subscriptions'].' subscription(s) at list price';
                $note .= $mrr['at_risk_cents'] > 0 ? ', '.Money::format($mrr['at_risk_cents'], $mrr['currency']).' of it past due' : '';
                $note .= $mrr['unknown_plan'] > 0 ? ", {$mrr['unknown_plan']} with an unknown plan excluded" : '';
                $first = Stat::make('MRR (calculated locally)', Money::format($mrr['cents'], $mrr['currency']))->description($note);
            }

            return [
                $first,
                Stat::make('Revenue reported by Stripe', 'Not configured')
                    ->description('Payments are not stored yet, so collected revenue, refunds and failed payments cannot be shown.')
                    ->color('gray'),
            ];
        }, 'Revenue');
    }
}

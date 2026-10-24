<?php

namespace App\Filament\Concerns;

use App\Support\Admin\PlatformMetrics;
use Closure;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

/**
 * Helpers for dashboard stat widgets: a failing query shows an "Unavailable" card instead of breaking the page.
 */
trait ReportsPlatformMetrics
{
    /**
     * @param  Closure(): array<int, Stat>  $stats
     * @return array<int, Stat>
     */
    protected function safely(Closure $stats, string $label): array
    {
        try {
            return $stats();
        } catch (Throwable $e) {
            report($e);

            return [Stat::make($label, 'Unavailable')->description('This figure could not be loaded. Try again shortly.')->color('danger')];
        }
    }

    /** "+3 vs previous 30 days" style comparison text. */
    protected function comparison(int $current, int $previous, string $noun): string
    {
        $days = PlatformMetrics::WINDOW_DAYS;
        $change = $current - $previous;
        $sign = $change > 0 ? '+' : '';

        return "{$current} {$noun} in the last {$days} days ({$sign}{$change} vs the {$days} before)";
    }
}

<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Filament\Widgets\Outreach\OutreachOutcomeChart;
use App\Filament\Widgets\Outreach\OutreachOverviewWidget;
use App\Filament\Widgets\Outreach\OutreachVolumeChart;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Sending statistics for email and WhatsApp. SMS is not sent by the platform yet, so it is not shown.
 */
class OutreachStatsPage extends Page
{
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = 'Outreach statistics';

    protected static ?string $slug = 'outreach/stats';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    public function getSubheading(): ?string
    {
        return 'Email and WhatsApp sending. SMS is not available yet.';
    }

    protected function getHeaderWidgets(): array
    {
        return [OutreachOverviewWidget::class, OutreachVolumeChart::class, OutreachOutcomeChart::class];
    }
}

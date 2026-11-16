<?php

namespace App\Filament\Widgets\Outreach;

use App\Enums\Outreach\OutreachMessageStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\OutreachMessage;
use Filament\Widgets\ChartWidget;

/**
 * Messages sent per day over the last 14 days, for each channel. One grouped query, so it costs the same however
 * many messages there are.
 */
class OutreachVolumeChart extends ChartWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $heading = 'Messages sent per day';

    protected ?string $description = 'Last 14 days';

    protected ?string $maxHeight = '280px';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn (int $ago) => now()->subDays($ago)->startOfDay());

        $counts = OutreachMessage::query()
            ->selectRaw('DATE(sent_at) as day, channel, COUNT(*) as total')
            ->where('status', OutreachMessageStatus::Sent)
            ->where('sent_at', '>=', $days->first())
            ->groupBy('day', 'channel')
            ->get()
            ->groupBy('channel');

        $series = function (string $channel) use ($days, $counts) {
            $byDay = ($counts[$channel] ?? collect())->keyBy(fn ($row) => (string) $row->day);

            return $days->map(fn ($day) => (int) ($byDay[$day->toDateString()]?->total ?? 0))->all();
        };

        return [
            'labels' => $days->map(fn ($day) => $day->format('d M'))->all(),
            'datasets' => [
                [
                    'label' => 'Email',
                    'data' => $series('email'),
                    'borderColor' => '#7c3aed',
                    'backgroundColor' => 'rgba(124, 58, 237, 0.14)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointRadius' => 3,
                    'pointBackgroundColor' => '#7c3aed',
                    'borderWidth' => 2.5,
                ],
                [
                    'label' => 'WhatsApp',
                    'data' => $series('whatsapp'),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointRadius' => 3,
                    'pointBackgroundColor' => '#16a34a',
                    'borderWidth' => 2.5,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'padding' => 16]]],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => 'rgba(148, 163, 184, 0.18)']],
            ],
        ];
    }
}

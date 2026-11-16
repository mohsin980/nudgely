<?php

namespace App\Filament\Widgets\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\OutreachMessage;
use Filament\Widgets\ChartWidget;

/**
 * Outcome of the last 30 days' attempts, per channel: sent, failed and skipped, side by side.
 */
class OutreachOutcomeChart extends ChartWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $heading = 'Outcome by channel';

    protected ?string $description = 'Last 30 days';

    protected ?string $maxHeight = '280px';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    protected function getData(): array
    {
        $rows = OutreachMessage::query()
            ->selectRaw('channel, status, COUNT(*) as total')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('channel', 'status')
            ->get();

        $count = fn (string $channel, string $status) => (int) ($rows->first(fn ($r) => (string) $r->channel === $channel && (string) $r->status === $status)?->total ?? 0);

        $bar = fn (string $label, string $status, string $color) => [
            'label' => $label,
            'data' => [$count('email', $status), $count('whatsapp', $status)],
            'backgroundColor' => $color,
            'borderRadius' => 8,
            'borderSkipped' => false,
            'maxBarThickness' => 56,
        ];

        return [
            'labels' => ['Email', 'WhatsApp'],
            'datasets' => [
                $bar('Sent', 'sent', '#16a34a'),
                $bar('Failed', 'failed', '#dc2626'),
                $bar('Skipped', 'skipped', '#94a3b8'),
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
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

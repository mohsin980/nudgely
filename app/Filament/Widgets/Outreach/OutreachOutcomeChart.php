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

    protected ?string $heading = 'Outcome, last 30 days';

    protected ?string $maxHeight = '260px';

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

        return [
            'labels' => ['Email', 'WhatsApp'],
            'datasets' => [
                ['label' => 'Sent', 'data' => [$count('email', 'sent'), $count('whatsapp', 'sent')], 'backgroundColor' => '#16a34a'],
                ['label' => 'Failed', 'data' => [$count('email', 'failed'), $count('whatsapp', 'failed')], 'backgroundColor' => '#dc2626'],
                ['label' => 'Skipped', 'data' => [$count('email', 'skipped'), $count('whatsapp', 'skipped')], 'backgroundColor' => '#94a3b8'],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}

<?php

namespace App\Filament\Widgets\Outreach;

use App\Enums\Outreach\OutreachMessageStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\OutreachMessage;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Last 30 days at a glance. Each card has its own colour and a 14-day trend line, so the numbers read as movement,
 * not just totals. Counts are grouped per day, so the cost doesn't grow with the log.
 */
class OutreachOverviewWidget extends StatsOverviewWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        return [
            $this->card('Emails sent', 'email', OutreachMessageStatus::Sent, 'success', Heroicon::OutlinedEnvelope, $since),
            $this->card('Emails failed', 'email', OutreachMessageStatus::Failed, 'danger', Heroicon::OutlinedExclamationTriangle, $since),
            $this->card('WhatsApp sent', 'whatsapp', OutreachMessageStatus::Sent, 'success', Heroicon::OutlinedChatBubbleLeftRight, $since),
            $this->card('WhatsApp failed', 'whatsapp', OutreachMessageStatus::Failed, 'danger', Heroicon::OutlinedExclamationTriangle, $since),
            $this->card('Skipped', null, OutreachMessageStatus::Skipped, 'gray', Heroicon::OutlinedForward, $since),
        ];
    }

    private function card(string $label, ?string $channel, OutreachMessageStatus $status, string $color, Heroicon $icon, $since): Stat
    {
        $query = fn () => OutreachMessage::query()
            ->where('status', $status)
            ->when($channel !== null, fn ($q) => $q->where('channel', $channel));

        $total = $query()->where('created_at', '>=', $since)->count();
        $trend = collect(range(13, 0))->map(fn (int $ago) => $query()
            ->whereDate('created_at', now()->subDays($ago)->toDateString())->count())->all();

        return Stat::make($label, number_format($total))
            ->description('Last 30 days')
            ->descriptionIcon(Heroicon::OutlinedClock)
            ->icon($icon)
            ->color($color)
            ->chart($trend);
    }
}

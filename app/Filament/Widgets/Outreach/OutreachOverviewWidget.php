<?php

namespace App\Filament\Widgets\Outreach;

use App\Enums\Outreach\OutreachMessageStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\OutreachMessage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Last 30 days, per channel: sent, failed and skipped. Counts only, so the cost doesn't grow with the log.
 */
class OutreachOverviewWidget extends StatsOverviewWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 10;

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        return [
            $this->stat('Emails sent (30 days)', 'email', OutreachMessageStatus::Sent, $since),
            $this->stat('Emails failed (30 days)', 'email', OutreachMessageStatus::Failed, $since),
            $this->stat('WhatsApp sent (30 days)', 'whatsapp', OutreachMessageStatus::Sent, $since),
            $this->stat('WhatsApp failed (30 days)', 'whatsapp', OutreachMessageStatus::Failed, $since),
            Stat::make('Skipped (30 days)', OutreachMessage::query()->where('status', OutreachMessageStatus::Skipped)->where('created_at', '>=', $since)->count())
                ->description('No consent, opted out, or messaged recently'),
        ];
    }

    private function stat(string $label, string $channel, OutreachMessageStatus $status, $since): Stat
    {
        return Stat::make($label, OutreachMessage::query()->where('channel', $channel)->where('status', $status)->where('created_at', '>=', $since)->count());
    }
}

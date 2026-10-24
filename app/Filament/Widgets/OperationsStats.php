<?php

namespace App\Filament\Widgets;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\Message;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform-wide operational counts from persisted rows. Counts only: no message content, recipients or errors.
 */
class OperationsStats extends StatsOverviewWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 30;

    protected ?string $heading = 'Operations (last 30 days)';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewUsage;
    }

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $sent = Message::query()->where('direction', MessageDirection::Outbound)
            ->where('created_at', '>=', $since)->whereIn('status', [MessageStatus::Sent, MessageStatus::Delivered])->count();
        $failed = Message::query()->where('direction', MessageDirection::Outbound)
            ->where('created_at', '>=', $since)->where('status', MessageStatus::Failed)->count();

        return [
            Stat::make('Emails sent', number_format($sent))
                ->description('Accepted by the provider')
                ->color('success'),
            Stat::make('Failed emails', number_format($failed))
                ->description($failed === 0 ? 'No failures recorded' : 'Failed on the sending side; see the audit trail for details')
                ->color($failed === 0 ? 'gray' : 'danger'),
        ];
    }
}

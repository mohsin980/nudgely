<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Widgets\Widget;

/**
 * Links to the platform's existing management pages. Each link is shown only when the signed-in administrator
 * can open that page, so the card never offers an action that would be refused.
 */
class QuickActions extends Widget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 1];

    protected string $view = 'filament.widgets.quick-actions';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewDashboard;
    }

    /**
     * @return list<array{title: string, description: string, url: string, icon: string}>
     */
    public function actions(): array
    {
        return collect([
            [OrganizationResource::class, 'Organizations', 'Browse and manage all organisations', 'heroicon-o-building-office-2'],
            [UserResource::class, 'Manage users', 'Add or update users', 'heroicon-o-users'],
            [SubscriptionResource::class, 'View subscriptions', 'Check billing and plans', 'heroicon-o-credit-card'],
            [AuditLogResource::class, 'View audit logs', 'System activity and changes', 'heroicon-o-shield-check'],
        ])
            ->filter(fn (array $action) => $action[0]::canViewAny())
            ->map(fn (array $action) => ['url' => $action[0]::getUrl(), 'title' => $action[1], 'description' => $action[2], 'icon' => $action[3]])
            ->values()
            ->all();
    }
}

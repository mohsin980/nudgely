<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\OrganizationActivity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Latest audited changes across businesses. Shows who-did-what labels only, never the change details
 * (which can hold business settings) or any customer message content.
 */
class RecentPlatformActivity extends TableWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewAuditLogs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent platform activity')
            ->query(OrganizationActivity::query()->with('organization:id,name')->latest('created_at')->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->tooltip(fn (OrganizationActivity $record) => $record->created_at?->toDayDateTimeString()),
                TextColumn::make('organization.name')->label('Organization')->placeholder('Deleted organization'),
                TextColumn::make('action')->label('Event')->state(fn (OrganizationActivity $record) => $record->label()),
            ])
            ->defaultPaginationPageOption(10)
            ->paginated([10])
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Business account and billing events will appear here.')
            ->poll(null);
    }
}

<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\PlatformAuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The latest platform administration events (suspensions, plan edits, role grants, refreshes): the sensitive actions
 * the audit log exists for. Shows who did what and the outcome; the full record is on the audit page.
 */
class RecentAdminActivity extends TableWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 55;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewAuditLogs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent admin activity')
            ->query(PlatformAuditLog::query()->with('actor:id,name,email')->latest('id')->limit(8))
            ->columns([
                TextColumn::make('created_at')->label('When')->since()
                    ->tooltip(fn (PlatformAuditLog $record) => $record->created_at?->toDayDateTimeString()),
                TextColumn::make('actor.name')->label('Administrator')->placeholder('System'),
                TextColumn::make('action')->label('Action')->state(fn (PlatformAuditLog $record) => $record->label()),
                TextColumn::make('outcome')->label('Outcome')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'success' => 'success',
                        'denied' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->recordUrl(fn (PlatformAuditLog $record) => AuditLogResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('No administrator activity yet')
            ->emptyStateDescription('Sensitive administrator actions are recorded here.')
            ->poll(null);
    }
}

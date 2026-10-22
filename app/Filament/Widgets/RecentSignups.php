<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The newest businesses. Name and sign-up time only; the detail page holds everything else.
 */
class RecentSignups extends TableWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOrganizations;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent organization signups')
            ->query(Organization::query()->latest('created_at')->latest('id')->limit(8))
            ->columns([
                TextColumn::make('name')->label('Business')->weight('medium'),
                TextColumn::make('created_at')->label('Signed up')->since()
                    ->tooltip(fn (Organization $record) => $record->created_at?->toDayDateTimeString()),
                TextColumn::make('status')->label('Status')
                    ->state(fn (Organization $record) => $record->isSuspended() ? 'Suspended' : 'Active')
                    ->badge()
                    ->color(fn (Organization $record) => $record->isSuspended() ? 'danger' : 'success'),
            ])
            ->recordUrl(fn (Organization $record) => OrganizationResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('No businesses have signed up yet')
            ->emptyStateDescription('New business accounts will appear here as they register.')
            ->poll(null);
    }
}

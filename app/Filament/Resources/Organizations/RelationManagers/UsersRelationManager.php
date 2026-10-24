<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Enums\Platform\PlatformPermission;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's members, read-only and paginated. Needs view_users on top of view_organizations.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Users';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return AdminAccess::allows(auth()->user(), PlatformPermission::ViewUsers, audit: false);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select(['id', 'organization_id', 'name', 'email', 'role', 'status', 'last_active_at', 'created_at']))
            ->defaultSort('created_at')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->state(fn (User $record) => $record->role->label()),
                TextColumn::make('status')->badge()->state(fn (User $record) => $record->status->label()),
                TextColumn::make('last_active_at')->label('Last active')->since()->placeholder('Never'),
            ])
            ->emptyStateHeading('No users');
    }
}

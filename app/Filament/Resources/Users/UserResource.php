<?php

namespace App\Filament\Resources\Users;

use App\Enums\OrganizationRole;
use App\Enums\Platform\PlatformPermission;
use App\Enums\Team\MemberStatus;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform-wide view of user accounts. Read-only except for deactivate / reactivate (see UserActions): there is no
 * create, edit, delete, impersonation, or way to grant platform roles. Only the columns the pages show are selected,
 * so password hashes and tokens are never even loaded.
 */
class UserResource extends Resource
{
    use RequiresPlatformPermission;

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 20;

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewUsers;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(['users.id', 'users.name', 'users.email', 'users.email_verified_at', 'users.organization_id', 'users.role', 'users.status',
                'users.suspended_at', 'users.last_active_at', 'users.created_at'])
            ->with(['organization:id,name', 'platformAdmin:id,user_id']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Search name or email')
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('email')->sortable()->searchable(),
                TextColumn::make('organization.name')->label('Organization')->placeholder('None (platform account)'),
                TextColumn::make('role')->state(fn (User $record) => $record->organization_id === null ? '—' : $record->role->label()),
                TextColumn::make('status')->badge()
                    ->state(fn (User $record) => $record->status->label())
                    ->color(fn (User $record) => match ($record->status) {
                        MemberStatus::Active => 'success',
                        MemberStatus::Suspended => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('email_verified_at')->label('Email verified')->state(fn (User $record) => $record->email_verified_at ? 'Yes' : 'No')->badge()
                    ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('organization_id')->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options(collect(MemberStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('role')
                    ->options(collect(OrganizationRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()),
                TernaryFilter::make('verified')->label('Email verified')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('users.email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('users.email_verified_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                UserActions::deactivate(),
                UserActions::reactivate(),
            ])
            ->emptyStateHeading('No users found');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Organizations;

use App\Billing\PlanCatalog;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Platform\OrganizationStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\UsersRelationManager;
use App\Filament\Resources\Organizations\Schemas\OrganizationInfolist;
use App\Models\Organization;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform-wide view of customer businesses. Read-only except for suspend / reactivate (see OrganizationActions):
 * there is no create, edit or delete. Queries here are explicit platform queries on Organization; they do not
 * touch how the customer app scopes its own data.
 */
class OrganizationResource extends Resource
{
    use RequiresPlatformPermission;

    protected static ?string $model = Organization::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $modelLabel = 'organization';

    protected static ?int $navigationSort = 10;

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOrganizations;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Only the columns the pages show; one aggregate for members and one eager load for the subscription. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(['organizations.id', 'organizations.name', 'organizations.created_at', 'organizations.suspended_at', 'organizations.suspended_by',
                'organizations.trial_ends_at', 'organizations.country', 'organizations.timezone', 'organizations.currency'])
            ->withCount('users')
            ->with('currentSubscription');
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrganizationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Search name or ID')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->searchable(query: fn (Builder $query, string $search) => ctype_digit($search) ? $query->orWhere('organizations.id', (int) $search) : $query),
                TextColumn::make('name')->label('Business')->sortable()->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Organization $record) => $record->status()->label())
                    ->color(fn (Organization $record) => $record->status()->color()),
                TextColumn::make('users_count')->label('Users')->sortable(),
                TextColumn::make('plan')->label('Plan')
                    ->state(fn (Organization $record) => $record->currentSubscription?->planDefinition()?->name ?? ($record->currentSubscription?->plan ?? 'Free (no subscription)')),
                TextColumn::make('subscription_status')->label('Subscription')->badge()
                    ->state(fn (Organization $record) => $record->currentSubscription?->status->label() ?? 'None')
                    ->color(fn (Organization $record) => match ($record->currentSubscription?->status) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::Trialing => 'info',
                        SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrganizationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'suspended' => $query->whereNotNull('organizations.suspended_at'),
                        'active' => $query->whereNull('organizations.suspended_at'),
                        default => $query,
                    }),
                SelectFilter::make('subscription_status')->label('Subscription status')
                    ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('subscriptions', fn (Builder $subs) => $subs->current()->where('status', $data['value']))
                        : $query),
                SelectFilter::make('plan')
                    ->options(fn () => collect(app(PlanCatalog::class)->all())->mapWithKeys(fn ($plan) => [$plan->key => $plan->name])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('subscriptions', fn (Builder $subs) => $subs->current()->where('plan', $data['value']))
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
                OrganizationActions::suspend(),
                OrganizationActions::reactivate(),
            ])
            ->emptyStateHeading('No organizations found')
            ->emptyStateDescription('Businesses appear here after they sign up.');
    }

    public static function getRelations(): array
    {
        return [UsersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}

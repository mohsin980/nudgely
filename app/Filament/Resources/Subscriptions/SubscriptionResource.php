<?php

namespace App\Filament\Resources\Subscriptions;

use App\Billing\PlanCatalog;
use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionInfolist;
use App\Models\Subscription;
use App\Support\Admin\AdminAccess;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of subscriptions as stored locally. The billing provider is the source of truth; every row says how
 * recently the provider confirmed it. The only write is a refresh from the provider (see SubscriptionRefresh):
 * no cancel, refund or plan change exists here. Access is decided by platform permissions, not by the customer-side
 * SubscriptionPolicy (which is about an owner's own billing).
 */
class SubscriptionResource extends Resource
{
    use RequiresPlatformPermission;

    protected static ?string $model = Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewSubscriptions;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('organization:id,name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('organization.name')->label('Organization')->searchable(),
                TextColumn::make('plan')->label('Plan')->state(fn (Subscription $record) => $record->planDefinition()?->name ?? "{$record->plan} (not in configuration)")->sortable(),
                TextColumn::make('status')->badge()
                    ->state(fn (Subscription $record) => $record->status->label())
                    ->color(fn (Subscription $record) => self::statusColor($record->status)),
                TextColumn::make('interval')->label('Interval')->state(fn (Subscription $record) => $record->planDefinition()?->interval->value ? ucfirst($record->planDefinition()->interval->value).'ly' : 'Unknown'),
                TextColumn::make('current_period_end')->label('Period ends')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('cancel_at_period_end')->label('Cancels at period end')->state(fn (Subscription $record) => $record->cancel_at_period_end ? 'Yes' : 'No')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('freshness')->label('Provider data')->badge()
                    ->state(fn (Subscription $record) => $record->dataFreshness()->label())
                    ->color(fn (Subscription $record) => $record->dataFreshness()->color()),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('plan')
                    ->options(fn () => collect(app(PlanCatalog::class)->all())->mapWithKeys(fn ($plan) => [$plan->key => $plan->name])->all()),
                SelectFilter::make('interval')->label('Billing interval')
                    ->options(collect(BillingInterval::cases())->mapWithKeys(fn ($i) => [$i->value => ucfirst($i->value).'ly'])->all())
                    ->query(function (Builder $query, array $data) {
                        $interval = BillingInterval::tryFrom((string) ($data['value'] ?? ''));

                        if ($interval === null) {
                            return $query;
                        }

                        // The interval belongs to the plan (config / provider price), not to the subscription row.
                        $plans = collect(app(PlanCatalog::class)->all())->filter(fn ($plan) => $plan->interval === $interval)->keys()->all();

                        return $query->whereIn('plan', $plans);
                    }),
                SelectFilter::make('organization_id')->label('Organization')->relationship('organization', 'name')->searchable(),
                SelectFilter::make('freshness')->label('Provider data')
                    ->options(['unconfirmed' => 'Not yet confirmed', 'stale' => 'Stale', 'confirmed' => 'Confirmed'])
                    ->query(function (Builder $query, array $data) {
                        $cutoff = now()->subMinutes((int) config('billing.stale_after_minutes', 180));
                        $live = $query->where('provider', '!=', 'manual');
                        $current = SubscriptionStatus::currentValues();

                        return match ($data['value'] ?? null) {
                            'unconfirmed' => $live->whereNull('last_synced_at'),
                            'stale' => $live->whereNotNull('last_synced_at')->where('last_synced_at', '<', $cutoff)->whereIn('status', $current),
                            'confirmed' => $live->whereNotNull('last_synced_at')->where(fn (Builder $q) => $q->where('last_synced_at', '>=', $cutoff)->orWhereNotIn('status', $current)),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No subscriptions yet')
            ->emptyStateDescription('Subscriptions appear here once a business starts a paid plan.');
    }

    public static function statusColor(SubscriptionStatus $status): string
    {
        return match ($status) {
            SubscriptionStatus::Active => 'success',
            SubscriptionStatus::Trialing => 'info',
            SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid => 'danger',
            SubscriptionStatus::Incomplete => 'warning',
            default => 'gray',
        };
    }

    public static function canSeePayments(): bool
    {
        return AdminAccess::allows(auth()->user(), PlatformPermission::ViewPayments, audit: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }
}

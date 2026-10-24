<?php

namespace App\Filament\Widgets;

use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use App\Models\Subscription;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Subscriptions the payment provider reports as past due or unpaid. The status is the provider's, as last synced;
 * no card or invoice details are stored or shown here.
 */
class RecentPaymentFailures extends TableWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewSubscriptions;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent payment failures')
            ->query(
                Subscription::query()
                    ->whereIn('status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Unpaid->value])
                    ->with('organization:id,name')
                    ->latest('updated_at')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('organization.name')->label('Business')->placeholder('Deleted organization'),
                TextColumn::make('plan')->label('Plan')->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('status')->label('Provider status')->badge()->color('warning')
                    ->formatStateUsing(fn (SubscriptionStatus $state) => $state->label()),
                TextColumn::make('updated_at')->label('Last changed')->since(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No payment failures')
            ->emptyStateDescription('Subscriptions that the payment provider reports as past due or unpaid will appear here.')
            ->poll(null);
    }
}

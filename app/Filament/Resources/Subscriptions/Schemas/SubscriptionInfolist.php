<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\BillingWebhookEvent;
use App\Models\Subscription;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * Subscription details. The stored values mirror the billing provider (Stripe), which is the source of truth; the
 * "Provider data" line says how recently it was confirmed. No secrets, card data or payloads are shown or stored.
 */
final class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription')->columns(3)->schema([
                TextEntry::make('organization.name')->label('Organization'),
                TextEntry::make('plan')->state(fn (Subscription $record) => $record->planDefinition()?->name ?? "{$record->plan} (not in configuration)"),
                TextEntry::make('status')->badge()
                    ->state(fn (Subscription $record) => $record->status->label())
                    ->color(fn (Subscription $record) => SubscriptionResource::statusColor($record->status)),
                TextEntry::make('interval')->label('Billing interval')
                    ->state(fn (Subscription $record) => $record->planDefinition() ? ucfirst($record->planDefinition()->interval->value).'ly' : 'Unknown'),
                TextEntry::make('provider')->label('Provider')->state(fn (Subscription $record) => ucfirst($record->provider)),
                TextEntry::make('provider_subscription_id')->label('Provider subscription ID')->copyable(),
                TextEntry::make('current_period_start')->label('Period start')->dateTime()->placeholder('—'),
                TextEntry::make('current_period_end')->label('Period end')->dateTime()->placeholder('—'),
                TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime()->placeholder('No trial'),
                TextEntry::make('cancel_at_period_end')->label('Cancels at period end')->state(fn (Subscription $record) => $record->cancel_at_period_end ? 'Yes' : 'No'),
                TextEntry::make('canceled_at')->label('Canceled at')->dateTime()->placeholder('—'),
                TextEntry::make('ended_at')->label('Ended at')->dateTime()->placeholder('—'),
                TextEntry::make('past_due_since')->label('Past due since')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label('Created locally')->dateTime(),
            ]),

            Section::make('Provider data')
                ->description('Stripe is the source of truth. This record is a stored copy kept up to date by webhooks and an hourly sync.')
                ->schema([
                    TextEntry::make('freshness')->label('Status of this copy')->badge()
                        ->state(fn (Subscription $record) => $record->dataFreshness()->label())
                        ->color(fn (Subscription $record) => $record->dataFreshness()->color()),
                    TextEntry::make('last_synced_at')->label('Last confirmed by provider')->dateTime()->placeholder('Never')
                        ->helperText('"Not yet confirmed" or "Stale" means the status above may differ from Stripe. Use Refresh from Stripe to check.'),
                ])->columns(2),

            Section::make('Invoices reported by Stripe')
                ->description('Read live from Stripe when you press "Load invoices from Stripe". Nothing is stored locally, so there is no local payment record to disagree with Stripe.')
                ->visible(fn () => SubscriptionResource::canSeePayments())
                ->schema([
                    Text::make(fn (ViewSubscription $livewire) => match (true) {
                        $livewire->invoicesError !== null => $livewire->invoicesError,
                        $livewire->invoices === null => 'Not loaded yet.',
                        $livewire->invoices === [] => "Stripe reported no invoices (loaded {$livewire->invoicesLoadedAt}).",
                        default => "Loaded from Stripe {$livewire->invoicesLoadedAt}. Amounts and statuses are as Stripe reports them.",
                    }),
                    RepeatableEntry::make('invoice_rows')->hiddenLabel()
                        ->state(fn (ViewSubscription $livewire) => $livewire->invoices ?? [])
                        ->columns(5)
                        ->schema([
                            TextEntry::make('reference')->label('Invoice'),
                            TextEntry::make('date'),
                            TextEntry::make('amount'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('provider_id')->label('Stripe reference'),
                        ])
                        ->visible(fn (ViewSubscription $livewire) => ! empty($livewire->invoices)),
                ]),

            Section::make('Webhook activity')
                ->description('The latest billing events Stripe sent for this organization (event type and processing result only, never the payload). Each event is processed once.')
                ->visible(fn () => SubscriptionResource::canSeePayments())
                ->schema([
                    RepeatableEntry::make('webhook_rows')->hiddenLabel()
                        ->state(fn (Subscription $record) => BillingWebhookEvent::query()->where('organization_id', $record->organization_id)->latest('id')->limit(10)->get()
                            ->map(fn (BillingWebhookEvent $event) => [
                                'received' => $event->created_at?->toDayDateTimeString(),
                                'type' => $event->event_type,
                                'result' => ucfirst($event->status->value).($event->attempts > 1 ? " ({$event->attempts} attempts)" : ''),
                                'detail' => $event->detail,
                            ])->all())
                        ->columns(4)
                        ->schema([TextEntry::make('received'), TextEntry::make('type'), TextEntry::make('result'), TextEntry::make('detail')->placeholder('—')])
                        ->placeholder('No webhook events recorded for this organization.'),
                ]),
        ]);
    }
}

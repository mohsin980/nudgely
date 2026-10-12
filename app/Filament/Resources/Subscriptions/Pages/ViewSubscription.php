<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Billing\InvoiceSummary;
use App\Exceptions\Billing\BillingException;
use App\Exceptions\Platform\SubscriptionRefreshException;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Platform\SubscriptionRefresh;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    /** @var list<array<string, mixed>>|null Invoices as Stripe reported them on request (never stored). */
    public ?array $invoices = null;

    public ?string $invoicesLoadedAt = null;

    public ?string $invoicesError = null;

    protected function getHeaderActions(): array
    {
        return [$this->loadInvoicesAction(), $this->refreshAction()];
    }

    /** Fetch the organization's invoices from the provider, on demand and read-only. */
    private function loadInvoicesAction(): Action
    {
        return Action::make('loadInvoices')
            ->label('Load invoices from Stripe')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn () => SubscriptionResource::canSeePayments() && $this->record->provider !== 'manual')
            ->action(function () {
                $this->invoices = null;
                $this->invoicesError = null;

                try {
                    $this->invoices = array_map(fn (InvoiceSummary $invoice) => [
                        'reference' => $invoice->number ?: $invoice->id,
                        'provider_id' => $invoice->id,
                        'date' => $invoice->date->toDayDateTimeString(),
                        'amount' => Money::format($invoice->amountCents, strtoupper($invoice->currency)),
                        'currency' => strtoupper($invoice->currency),
                        'status' => ucfirst(str_replace('_', ' ', $invoice->status)),
                    ], app(BillingService::class)->invoicesFor($this->record->organization));
                    $this->invoicesLoadedAt = now()->toDayDateTimeString();
                } catch (BillingException) {
                    $this->invoicesError = 'Stripe could not be reached, so no invoice data is shown. This does not mean there are none.';
                }
            });
    }

    /** Re-read this subscription from the provider through the existing billing service. */
    private function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh from Stripe')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => (auth()->user()?->can('manage_subscriptions') ?? false) && $this->record->provider !== 'manual')
            ->requiresConfirmation()
            ->modalHeading('Refresh from Stripe?')
            ->modalDescription('Re-reads this subscription from Stripe and updates the stored record to match. It does not change the subscription at Stripe.')
            ->action(function () {
                try {
                    $this->record = app(SubscriptionRefresh::class)->refresh($this->record, auth()->user())->load('organization:id,name');
                } catch (AuthorizationException) {
                    Notification::make()->title('You are not allowed to do this.')->danger()->send();

                    return;
                } catch (SubscriptionRefreshException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Refreshed from Stripe')->success()->send();
            });
    }
}

<?php

namespace App\Livewire\Settings;

use App\Billing\InvoiceSummary;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\BillingException;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Billing\BillingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Past invoices from the billing provider, with links to its hosted invoice pages.
 */
#[Layout('components.layouts.app')]
#[Title('Invoices')]
class BillingHistory extends Component
{
    use SettingsPage;

    public function mount(): void
    {
        $this->authorize(Permission::ManageBilling->value);
    }

    public function render(BillingService $billing)
    {
        $invoices = [];
        $error = null;

        try {
            /** @var list<InvoiceSummary> $invoices */
            $invoices = $billing->invoices($this->user());
        } catch (BillingException) {
            $error = "Invoices can't be loaded right now. Please try again in a few minutes.";
        }

        return view('livewire.settings.billing-history', [
            'organization' => $this->organization(),
            'invoices' => $invoices,
            'error' => $error,
            'hasBillingAccount' => $this->organization()->billing_customer_id !== null,
        ]);
    }
}

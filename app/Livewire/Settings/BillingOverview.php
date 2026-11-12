<?php

namespace App\Livewire\Settings;

use App\Enums\Billing\LimitKey;
use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\ManagesSubscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Billing home (owner): the current plan, what happens next, and the saved card. Paying and card
 * details happen on the provider's hosted checkout and billing portal; this page only starts those.
 */
#[Layout('components.layouts.app')]
#[Title('Billing')]
class BillingOverview extends Component
{
    use ManagesSubscription;

    public function mount(BillingService $billing): void
    {
        $this->authorize(Permission::ManageBilling->value);

        if (session()->has('billing-error')) {
            $this->failed((string) session('billing-error'));
        }

        // Back from the provider: record the checkout, or pick up portal changes.
        $sessionId = request()->query('session_id');

        if (request()->query('checkout') === 'success' && is_string($sessionId) && preg_match('/^[A-Za-z0-9_]{1,255}$/', $sessionId)) {
            $this->attempt(function () use ($billing, $sessionId) {
                $subscription = $billing->completeCheckout($this->user(), $sessionId);
                $this->saved($subscription->onTrial()
                    ? "Your {$subscription->planDefinition()?->name} trial has started. It ends on {$this->organization()->formatDate($subscription->trial_ends_at)}."
                    : "You're now on the {$subscription->planDefinition()?->name} plan.");
            });
        } elseif (request()->query('checkout') === 'cancelled') {
            $this->statusMessage = 'Checkout was cancelled. Nothing was charged.';
            $this->statusType = 'info';
        } elseif (request()->boolean('portal')) {
            $billing->forgetProviderCache($this->organization());
            $this->attempt(fn () => $billing->refresh($this->organization()));
        }
    }

    public function render(EntitlementService $entitlements, BillingService $billing)
    {
        $organization = $this->organization();
        $subscription = $billing->currentSubscription($organization);

        return view('livewire.settings.billing-overview', [
            'organization' => $organization,
            'plan' => $entitlements->plan($organization),
            'subscription' => $subscription,
            'currency' => $this->billingCurrency($subscription),
            'notice' => $subscription ? $this->describe($subscription) : null,
            'nextBilling' => $subscription ? $this->nextBilling($subscription) : null,
            'signupTrial' => $entitlements->trial($organization),
            'trialDays' => $billing->trialDaysFor($organization),
            'payment' => $this->payment($billing),
            'atLimit' => array_map(fn (string $key) => LimitKey::from($key)->shortName(), array_keys(array_filter($entitlements->summary($organization), fn (array $row) => $row['limit'] !== null && $row['limit'] > 0 && $row['used'] >= $row['limit']))),
        ]);
    }
}

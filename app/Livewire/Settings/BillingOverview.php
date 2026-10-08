<?php

namespace App\Livewire\Settings;

use App\Billing\PaymentMethodSummary;
use App\Enums\Billing\LimitKey;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\BillingException;
use App\Livewire\Settings\Concerns\ManagesSubscription;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use Carbon\CarbonInterface;
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
            'notice' => $subscription ? $this->describe($subscription) : null,
            'nextBilling' => $subscription ? $this->nextBilling($subscription) : null,
            'signupTrial' => $entitlements->trial($organization),
            'trialDays' => $billing->trialDaysFor($organization),
            'payment' => $this->payment($billing),
            'atLimit' => array_map(fn (string $key) => LimitKey::from($key)->shortName(), array_keys(array_filter($entitlements->summary($organization), fn (array $row) => $row['limit'] !== null && $row['used'] >= $row['limit']))),
        ]);
    }

    /**
     * The saved card, or an explanation when the provider can't be asked right now.
     *
     * @return array{card: ?PaymentMethodSummary, error: ?string}
     */
    private function payment(BillingService $billing): array
    {
        if ($this->organization()->billing_customer_id === null) {
            return ['card' => null, 'error' => null];
        }

        try {
            return ['card' => $billing->paymentMethod($this->user()), 'error' => null];
        } catch (BillingException) {
            return ['card' => null, 'error' => "Payment details can't be loaded right now."];
        }
    }

    /**
     * What the next charge is, if there is one: [label, date, plan name]. Cancelling subscriptions have none.
     *
     * @return array{date: CarbonInterface, plan: string}|null
     */
    private function nextBilling(Subscription $subscription): ?array
    {
        if ($subscription->cancel_at_period_end || $subscription->current_period_end === null || ! $subscription->grantsAccess()) {
            return null;
        }

        $next = $subscription->scheduled_plan !== null ? $subscription->scheduledPlanDefinition() : $subscription->planDefinition();

        return $next === null ? null : ['date' => $subscription->current_period_end, 'plan' => $next->name];
    }
}

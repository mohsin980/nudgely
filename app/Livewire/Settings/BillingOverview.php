<?php

namespace App\Livewire\Settings;

use App\Billing\Plan;
use App\Enums\Billing\LimitKey;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\BillingException;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Plan, subscription and usage (owner). Paying and card details happen on the provider's
 * hosted checkout and billing portal; this page only starts those and shows the result.
 */
#[Layout('components.layouts.app')]
#[Title('Billing')]
class BillingOverview extends Component
{
    use SettingsPage;

    public function mount(BillingService $billing): void
    {
        $this->authorize(Permission::ManageBilling->value);

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
            $this->attempt(fn () => $billing->refresh($this->organization()));
        }
    }

    /**
     * Free → paid: hosted checkout. Paid → another plan: upgrade now, downgrade at period end.
     */
    public function choosePlan(string $planKey, BillingService $billing): void
    {
        $this->authorize(Permission::ManageBilling->value);

        $this->attempt(function () use ($billing, $planKey) {
            if ($billing->currentSubscription($this->organization()) === null) {
                $session = $billing->startCheckout($this->user(), $planKey,
                    route('settings.billing').'?checkout=success&session_id={CHECKOUT_SESSION_ID}',
                    route('settings.billing', ['checkout' => 'cancelled']));
                $this->redirect($session->url);

                return;
            }

            $subscription = $billing->changePlan($this->user(), $planKey);
            $this->saved($this->describe($subscription));
        });
    }

    public function cancelSubscription(BillingService $billing): void
    {
        $this->authorize(Permission::ManageBilling->value);
        $this->attempt(fn () => $this->saved($this->describe($billing->cancel($this->user(), atPeriodEnd: true))));
    }

    public function resumeSubscription(BillingService $billing): void
    {
        $this->authorize(Permission::ManageBilling->value);
        $this->attempt(fn () => $this->saved('Your subscription will continue. '.$this->describe($billing->resume($this->user()))));
    }

    public function openPortal(BillingService $billing): void
    {
        $this->authorize(Permission::ManageBilling->value);
        $this->attempt(fn () => $this->redirect($billing->portalUrl($this->user(), route('settings.billing', ['portal' => 1]))));
    }

    public function render(EntitlementService $entitlements, BillingService $billing, UsageService $usage)
    {
        $organization = $this->organization();
        [$start, $end] = $usage->period($organization);
        $subscription = $billing->currentSubscription($organization);
        $plan = $entitlements->plan($organization);

        return view('livewire.settings.billing-overview', [
            'organization' => $organization,
            'plan' => $plan,
            'subscription' => $subscription,
            'notice' => $subscription ? $this->describe($subscription) : null,
            'rows' => $entitlements->summary($organization),
            'plans' => $billing->plans(),
            'trialDays' => $billing->trialDaysFor($organization),
            'signupTrial' => $entitlements->trial($organization),
            'overLimits' => fn (Plan $target) => $this->overLimits($target, $entitlements->summary($organization)),
            'periodStart' => $start,
            'periodEnd' => $end->subSecond(),
        ]);
    }

    /**
     * The sentence the page shows for the subscription's state.
     */
    private function describe(Subscription $subscription): string
    {
        $organization = $this->organization();
        $name = $subscription->planDefinition()?->name ?? ucfirst($subscription->plan);

        return match (true) {
            $subscription->cancel_at_period_end && $subscription->current_period_end !== null => "Your subscription will remain active until {$organization->formatDate($subscription->current_period_end)}. After that you'll be on the Free plan.",
            $subscription->scheduled_plan !== null => "You're on {$name} until {$organization->formatDate($subscription->scheduled_change_at)}, then {$subscription->scheduledPlanDefinition()?->name}. Nothing is deleted.",
            $subscription->onTrial() => "Your {$name} trial ends on {$organization->formatDate($subscription->trial_ends_at)}.",
            default => "You're on the {$name} plan.",
        };
    }

    /**
     * Limits the business already exceeds on a smaller plan (data is kept; new items would be limited).
     *
     * @param  array<string, array{label: string, used: int}>  $summary
     * @return list<string>
     */
    private function overLimits(Plan $target, array $summary): array
    {
        $over = [];

        foreach (LimitKey::cases() as $key) {
            $limit = $target->limit($key);

            if ($limit !== null && ($summary[$key->value]['used'] ?? 0) > $limit) {
                $over[] = "{$summary[$key->value]['label']}: {$summary[$key->value]['used']} of {$limit}";
            }
        }

        return $over;
    }

    private function attempt(\Closure $action): void
    {
        try {
            $action();
        } catch (BillingException $e) {
            $this->failed($e->getMessage());
        }
    }
}

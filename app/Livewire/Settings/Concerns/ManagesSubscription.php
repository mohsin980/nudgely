<?php

namespace App\Livewire\Settings\Concerns;

use App\Enums\Team\Permission;
use App\Exceptions\Billing\BillingException;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The subscription actions of the billing pages. Each one authorizes on the server and then calls
 * BillingService, which holds all the billing rules; nothing is decided here.
 */
trait ManagesSubscription
{
    use SettingsPage;

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

    /**
     * The sentence the pages show for the subscription's state.
     */
    protected function describe(Subscription $subscription): string
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

    protected function attempt(\Closure $action): void
    {
        // Each of these makes the payment provider do work, so a script can't hammer it for a business.
        $key = 'billing-actions:'.$this->organization()->id;

        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->failed('Too many billing requests. Please wait a few minutes and try again.');

            return;
        }

        RateLimiter::hit($key, 600);

        try {
            $action();
        } catch (BillingException $e) {
            $this->failed($e->getMessage());
        }
    }
}

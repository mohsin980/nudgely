<?php

namespace App\Contracts\Billing;

use App\Billing\Plan;
use App\Billing\ProviderSubscription;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\Subscription;

/**
 * What QuoteFollow needs from a billing provider (Stripe later; "manual" now).
 * Only BillingService calls this; the rest of the app asks EntitlementService.
 */
interface BillingProviderInterface
{
    /**
     * Provider key stored with subscriptions, e.g. "manual" or "stripe".
     */
    public function name(): string;

    /**
     * Create the organization's customer at the provider; returns the provider's customer ID.
     *
     * @throws BillingException
     */
    public function createCustomer(Organization $organization): string;

    /**
     * @throws BillingException
     */
    public function createSubscription(string $customerId, Plan $plan, int $trialDays = 0): ProviderSubscription;

    /**
     * @throws BillingException
     */
    public function changePlan(Subscription $subscription, Plan $plan): ProviderSubscription;

    /**
     * Cancel now, or at the end of the paid period.
     *
     * @throws BillingException
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): ProviderSubscription;

    /**
     * Undo a cancellation scheduled for the end of the period.
     *
     * @throws BillingException
     */
    public function resume(Subscription $subscription): ProviderSubscription;
}

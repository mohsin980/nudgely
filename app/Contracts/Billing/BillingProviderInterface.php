<?php

namespace App\Contracts\Billing;

use App\Billing\CheckoutSession;
use App\Billing\CompletedCheckout;
use App\Billing\Plan;
use App\Billing\ProviderSubscription;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * What QuoteFollow needs from a billing provider ("stripe", or "manual" locally).
 * Only BillingService calls this; the rest of the app asks EntitlementService.
 * Card details are always entered on the provider's hosted pages, never in QuoteFollow.
 * Every method throws BillingException (with a message safe to show) when the provider fails.
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
     * A hosted checkout page for a new subscription (optionally starting with a trial).
     *
     * @throws BillingException
     */
    public function createCheckout(Organization $organization, string $customerId, Plan $plan, int $trialDays, string $successUrl, string $cancelUrl): CheckoutSession;

    /**
     * The result of a checkout, or null while it isn't complete.
     *
     * @throws BillingException
     */
    public function completedCheckout(string $sessionId): ?CompletedCheckout;

    /**
     * Start a subscription without checkout (manual provider / internal accounts).
     *
     * @throws BillingException
     */
    public function createSubscription(string $customerId, Plan $plan, int $trialDays = 0): ProviderSubscription;

    /**
     * Switch plan now, prorating the difference (upgrades).
     *
     * @throws BillingException
     */
    public function changePlan(Subscription $subscription, Plan $plan): ProviderSubscription;

    /**
     * Switch plan at a future time, normally the end of the paid period (downgrades).
     *
     * @throws BillingException
     */
    public function scheduleChange(Subscription $subscription, Plan $plan, CarbonImmutable $at): ProviderSubscription;

    /**
     * Drop a scheduled plan change; the subscription keeps its current plan.
     *
     * @throws BillingException
     */
    public function cancelScheduledChange(Subscription $subscription): ProviderSubscription;

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

    /**
     * The subscription as the provider has it now (synchronization).
     *
     * @throws BillingException
     */
    public function retrieveSubscription(Subscription $subscription): ProviderSubscription;

    /**
     * A subscription by the provider's ID (used by webhooks, which only say "this changed").
     *
     * @throws BillingException
     */
    public function fetchSubscription(string $providerSubscriptionId): ProviderSubscription;

    /**
     * A hosted page where the customer manages payment methods, billing details and invoices.
     *
     * @throws BillingException
     */
    public function createPortalSession(string $customerId, string $returnUrl): string;
}

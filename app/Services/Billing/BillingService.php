<?php

namespace App\Services\Billing;

use App\Billing\CheckoutSession;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Contracts\Billing\BillingProviderInterface;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place that talks to the billing provider. Every action is for the actor's own
 * organization (never one from the request), needs the manage-billing permission, and is
 * recorded in the organization activity log. The local Subscription row mirrors the provider.
 *
 * Provider calls happen outside database transactions; only the resulting state is written
 * in one. Plan changes: upgrades apply now (prorated), downgrades wait for the period end,
 * and going back to Free cancels at the period end. Nothing is deleted when a plan shrinks.
 */
class BillingService
{
    public function __construct(
        private readonly BillingProviderManager $providers,
        private readonly PlanCatalog $plans,
    ) {}

    public function provider(): BillingProviderInterface
    {
        return $this->providers->driver();
    }

    public function currentSubscription(Organization $organization): ?Subscription
    {
        return Subscription::query()->where('organization_id', $organization->id)->current()->latest('id')->first();
    }

    /**
     * Days of free trial a new subscription would start with: the configured trial for a
     * business that never had one, otherwise none.
     */
    public function trialDaysFor(Organization $organization): int
    {
        $hadTrial = $organization->trial_used_at !== null
            || Subscription::query()->where('organization_id', $organization->id)->whereNotNull('trial_ends_at')->exists();

        return $hadTrial ? 0 : max(0, (int) config('billing.trial_days'));
    }

    /**
     * A hosted checkout page for a paid plan (Free → Starter/Pro). The card is entered at the provider.
     *
     * @throws AuthorizationException|BillingException
     */
    public function startCheckout(User $actor, string $planKey, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $organization = $this->authorize($actor);
        $plan = $this->paidPlan($planKey);

        if ($this->currentSubscription($organization) !== null) {
            throw new BillingException('This business already has a subscription. Change its plan instead.');
        }

        $provider = $this->provider();
        $session = $provider->createCheckout($organization, $this->customerId($organization, $provider), $plan, $this->trialDaysFor($organization), $successUrl, $cancelUrl);

        Log::info('Checkout started.', ['organization_id' => $organization->id, 'plan' => $plan->key, 'provider' => $provider->name()]);

        return $session;
    }

    /**
     * Record the subscription a finished checkout created. Safe to call twice (e.g. a reload
     * of the success page); a checkout of another organization is refused.
     *
     * @throws AuthorizationException|BillingException
     */
    public function completeCheckout(User $actor, string $sessionId): Subscription
    {
        $organization = $this->authorize($actor);
        $provider = $this->provider();
        $completed = $provider->completedCheckout($sessionId) ?? throw new BillingException('The checkout isn\'t complete yet. If you paid, refresh this page in a moment.');

        if ($completed->organizationId !== $organization->id || $completed->customerId === null || $completed->customerId !== $organization->billing_customer_id) {
            Log::warning('Checkout completion rejected: it belongs to another organization.', ['organization_id' => $organization->id]);

            throw new BillingException('This checkout belongs to another business.');
        }

        $existed = Subscription::query()->where('provider', $provider->name())->where('provider_subscription_id', $completed->subscription->id)->exists();
        $subscription = $this->sync($organization, $provider->name(), $completed->subscription);

        if (! $existed) {
            OrganizationActivity::record($organization, 'subscription_started', $actor, ['plan' => $subscription->plan, 'status' => $subscription->status->value, 'trial' => $subscription->trial_ends_at !== null]);
        }

        return $subscription;
    }

    /**
     * Start a subscription without checkout (manual provider / internal accounts).
     *
     * @throws AuthorizationException|BillingException
     */
    public function subscribe(User $actor, string $planKey, int $trialDays = 0): Subscription
    {
        $organization = $this->authorize($actor);
        $plan = $this->paidPlan($planKey);

        if ($trialDays < 0 || $trialDays > 90) {
            throw new BillingException('A trial can be up to 90 days.');
        }

        if ($this->currentSubscription($organization) !== null) {
            throw new BillingException('This business already has a subscription. Change its plan instead.');
        }

        $provider = $this->provider();
        $remote = $provider->createSubscription($this->customerId($organization, $provider), $plan, $trialDays);
        $subscription = $this->sync($organization, $provider->name(), $remote);

        OrganizationActivity::record($organization, 'subscription_started', $actor, ['plan' => $plan->key, 'status' => $subscription->status->value]);
        Log::info('Subscription started.', ['organization_id' => $organization->id, 'subscription_id' => $subscription->id, 'plan' => $plan->key]);

        return $subscription;
    }

    /**
     * Move to another plan: a more expensive plan applies now (prorated); a cheaper paid plan
     * is scheduled for the end of the paid period; Free cancels at the period end. Choosing the
     * current plan drops a scheduled downgrade.
     *
     * @throws AuthorizationException|BillingException
     */
    public function changePlan(User $actor, string $planKey): Subscription
    {
        $organization = $this->authorize($actor);
        $plan = $this->plans->get($planKey);
        $subscription = $this->currentSubscription($organization);

        if ($subscription === null) {
            throw new BillingException($plan->isFree() ? 'This business is already on the free plan.' : 'Start a subscription with checkout first.');
        }

        if ($plan->isFree()) {
            return $this->cancel($actor, atPeriodEnd: true);
        }

        $provider = $this->providerFor($subscription);
        $current = $subscription->planDefinition();
        $from = $subscription->plan;

        if ($subscription->plan === $plan->key) {
            if ($subscription->scheduled_plan === null) {
                return $subscription;
            }

            $subscription = $this->apply($subscription, $provider->cancelScheduledChange($subscription));
            OrganizationActivity::record($organization, 'subscription_change_cancelled', $actor, ['plan' => $plan->key]);

            return $subscription;
        }

        if ($current === null || $plan->priceCents > $current->priceCents) {
            $subscription = $this->apply($subscription, $provider->changePlan($subscription, $plan));
            OrganizationActivity::record($organization, 'subscription_plan_changed', $actor, ['changes' => ['Plan' => ['from' => $from, 'to' => $plan->key]]]);

            return $subscription;
        }

        $at = $subscription->current_period_end ?? $subscription->trial_ends_at
            ?? throw new BillingException('The current billing period end is unknown. Please try again later.');
        $subscription = $this->apply($subscription, $provider->scheduleChange($subscription, $plan, CarbonImmutable::instance($at)));
        OrganizationActivity::record($organization, 'subscription_downgrade_scheduled', $actor, ['from' => $from, 'to' => $plan->key, 'at' => $subscription->scheduled_change_at?->toIso8601String()]);

        return $subscription;
    }

    /**
     * Cancel, by default at the end of the paid period (the plan stays until then).
     *
     * @throws AuthorizationException|BillingException
     */
    public function cancel(User $actor, bool $atPeriodEnd = true): Subscription
    {
        $organization = $this->authorize($actor);
        $subscription = $this->currentSubscription($organization) ?? throw new BillingException('This business has no subscription.');

        $subscription = $this->apply($subscription, $this->providerFor($subscription)->cancel($subscription, $atPeriodEnd));
        OrganizationActivity::record($organization, 'subscription_cancelled', $actor, ['plan' => $subscription->plan, 'at_period_end' => $atPeriodEnd]);

        return $subscription;
    }

    /**
     * Undo a cancellation scheduled for the end of the period.
     *
     * @throws AuthorizationException|BillingException
     */
    public function resume(User $actor): Subscription
    {
        $organization = $this->authorize($actor);
        $subscription = $this->currentSubscription($organization) ?? throw new BillingException('This business has no subscription.');

        if (! $subscription->cancel_at_period_end) {
            throw new BillingException('This subscription is not scheduled to cancel.');
        }

        $subscription = $this->apply($subscription, $this->providerFor($subscription)->resume($subscription));
        OrganizationActivity::record($organization, 'subscription_resumed', $actor, ['plan' => $subscription->plan]);

        return $subscription;
    }

    /**
     * The provider's hosted page for payment methods, billing details and invoices.
     *
     * @throws AuthorizationException|BillingException
     */
    public function portalUrl(User $actor, string $returnUrl): string
    {
        $organization = $this->authorize($actor);
        $provider = $this->provider();

        if ($organization->billing_customer_id === null || $organization->billing_provider !== $provider->name()) {
            throw new BillingException('There is no billing account yet. Choose a plan first.');
        }

        return $provider->createPortalSession($organization->billing_customer_id, $returnUrl);
    }

    /**
     * Synchronize the organization's current subscription with the provider (no actor: used
     * by the scheduler and after returning from the provider's pages).
     *
     * @throws BillingException
     */
    public function refresh(Organization $organization): ?Subscription
    {
        $subscription = $this->currentSubscription($organization);

        return $subscription === null ? null : $this->apply($subscription, $this->providerFor($subscription)->retrieveSubscription($subscription));
    }

    /**
     * Refresh every current subscription; one failure doesn't stop the others.
     *
     * @return array{synced: int, failed: int}
     */
    public function refreshAll(): array
    {
        $result = ['synced' => 0, 'failed' => 0];

        Subscription::query()->current()->orderBy('id')->chunkById(100, function ($subscriptions) use (&$result) {
            foreach ($subscriptions as $subscription) {
                try {
                    $this->apply($subscription, $this->providerFor($subscription)->retrieveSubscription($subscription));
                    $result['synced']++;
                } catch (BillingException $e) {
                    $result['failed']++;
                    Log::warning('Subscription sync failed.', ['subscription_id' => $subscription->id, 'organization_id' => $subscription->organization_id]);
                }
            }
        });

        return $result;
    }

    /**
     * Record what the provider reports. Idempotent; a provider subscription can never move
     * to another organization.
     *
     * @throws BillingException
     */
    public function sync(Organization $organization, string $provider, ProviderSubscription $remote): Subscription
    {
        $this->plans->get($remote->planKey);

        return DB::transaction(function () use ($organization, $provider, $remote) {
            $existing = Subscription::query()->where('provider', $provider)->where('provider_subscription_id', $remote->id)->lockForUpdate()->first();

            if ($existing !== null && $existing->organization_id !== $organization->id) {
                Log::warning('Billing sync rejected: subscription belongs to another organization.', ['subscription_id' => $existing->id, 'organization_id' => $organization->id]);

                throw new BillingException('This subscription belongs to another organization.');
            }

            $subscription = $existing !== null ? $this->apply($existing, $remote) : $this->store($organization, $provider, $remote);

            // A business gets one free trial, ever.
            if ($remote->trialEndsAt !== null && $organization->trial_used_at === null) {
                Organization::query()->whereKey($organization->id)->whereNull('trial_used_at')->update(['trial_used_at' => now()]);
                $organization->trial_used_at = now();
            }

            return $subscription;
        });
    }

    /**
     * @return array<string, Plan>
     */
    public function plans(): array
    {
        return $this->plans->all();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor): Organization
    {
        if (! $actor->hasPermission(Permission::ManageBilling) || $actor->organization === null) {
            throw new AuthorizationException('Only the owner can manage billing.');
        }

        return $actor->organization;
    }

    /**
     * @throws BillingException
     */
    private function paidPlan(string $planKey): Plan
    {
        $plan = $this->plans->get($planKey);

        if ($plan->isFree()) {
            throw new BillingException('The free plan needs no subscription.');
        }

        return $plan;
    }

    /**
     * The organization's customer at the provider, created once (the provider call happens
     * outside the lock; a provider idempotency key prevents duplicates).
     */
    private function customerId(Organization $organization, BillingProviderInterface $provider): string
    {
        if ($organization->billing_customer_id !== null && $organization->billing_provider === $provider->name()) {
            return $organization->billing_customer_id;
        }

        $customerId = $provider->createCustomer($organization);

        return DB::transaction(function () use ($organization, $provider, $customerId) {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if ($locked->billing_customer_id !== null && $locked->billing_provider === $provider->name()) {
                $organization->forceFill(['billing_customer_id' => $locked->billing_customer_id, 'billing_provider' => $locked->billing_provider]);

                return $locked->billing_customer_id;
            }

            $locked->forceFill(['billing_provider' => $provider->name(), 'billing_customer_id' => $customerId])->save();
            $organization->forceFill(['billing_provider' => $provider->name(), 'billing_customer_id' => $customerId]);

            return $customerId;
        });
    }

    private function providerFor(Subscription $subscription): BillingProviderInterface
    {
        return $this->providers->driver($subscription->provider);
    }

    private function store(Organization $organization, string $provider, ProviderSubscription $remote): Subscription
    {
        $subscription = new Subscription;
        $subscription->forceFill(['organization_id' => $organization->id, 'provider' => $provider] + $remote->toAttributes())->save();

        return $subscription;
    }

    private function apply(Subscription $subscription, ProviderSubscription $remote): Subscription
    {
        $this->plans->get($remote->planKey);
        $subscription->forceFill($remote->toAttributes())->save();

        return $subscription;
    }
}

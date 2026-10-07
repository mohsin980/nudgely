<?php

namespace App\Services\Billing;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place that talks to the billing provider. Every action is for the actor's own
 * organization (never one from the request), needs the manage-billing permission, and is
 * recorded in the organization activity log. The local Subscription row mirrors the provider.
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
     * Start a paid plan. An organization has at most one current subscription.
     *
     * @throws AuthorizationException|BillingException
     */
    public function subscribe(User $actor, string $planKey, int $trialDays = 0): Subscription
    {
        $organization = $this->authorize($actor);
        $plan = $this->plans->get($planKey);

        if ($plan->isFree()) {
            throw new BillingException('The free plan needs no subscription.');
        }

        if ($trialDays < 0 || $trialDays > 90) {
            throw new BillingException('A trial can be up to 90 days.');
        }

        return DB::transaction(function () use ($actor, $organization, $plan, $trialDays) {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if ($this->currentSubscription($organization) !== null) {
                throw new BillingException('This business already has a subscription. Change its plan instead.');
            }

            $provider = $this->provider();
            $customerId = $this->customerId($organization, $provider);
            $remote = $provider->createSubscription($customerId, $plan, $trialDays);
            $subscription = $this->store($organization, $provider->name(), $remote);

            OrganizationActivity::record($organization, 'subscription_started', $actor, ['plan' => $plan->key, 'status' => $subscription->status->value]);
            Log::info('Subscription started.', ['organization_id' => $organization->id, 'subscription_id' => $subscription->id, 'plan' => $plan->key]);

            return $subscription;
        });
    }

    /**
     * Move the current subscription to another paid plan.
     *
     * @throws AuthorizationException|BillingException
     */
    public function changePlan(User $actor, string $planKey): Subscription
    {
        $organization = $this->authorize($actor);
        $plan = $this->plans->get($planKey);

        if ($plan->isFree()) {
            throw new BillingException('To go back to the free plan, cancel the subscription.');
        }

        return $this->withCurrent($organization, function (Subscription $subscription) use ($actor, $plan) {
            if ($subscription->plan === $plan->key) {
                return $subscription;
            }

            $from = $subscription->plan;
            $subscription = $this->apply($subscription, $this->providerFor($subscription)->changePlan($subscription, $plan));
            OrganizationActivity::record($subscription->organization_id, 'subscription_plan_changed', $actor, ['changes' => ['Plan' => ['from' => $from, 'to' => $plan->key]]]);

            return $subscription;
        });
    }

    /**
     * @throws AuthorizationException|BillingException
     */
    public function cancel(User $actor, bool $atPeriodEnd = true): Subscription
    {
        $organization = $this->authorize($actor);

        return $this->withCurrent($organization, function (Subscription $subscription) use ($actor, $atPeriodEnd) {
            $subscription = $this->apply($subscription, $this->providerFor($subscription)->cancel($subscription, $atPeriodEnd));
            OrganizationActivity::record($subscription->organization_id, 'subscription_cancelled', $actor, ['plan' => $subscription->plan, 'at_period_end' => $atPeriodEnd]);

            return $subscription;
        });
    }

    /**
     * @throws AuthorizationException|BillingException
     */
    public function resume(User $actor): Subscription
    {
        $organization = $this->authorize($actor);

        return $this->withCurrent($organization, function (Subscription $subscription) use ($actor) {
            if (! $subscription->cancel_at_period_end) {
                throw new BillingException('This subscription is not scheduled to cancel.');
            }

            $subscription = $this->apply($subscription, $this->providerFor($subscription)->resume($subscription));
            OrganizationActivity::record($subscription->organization_id, 'subscription_resumed', $actor, ['plan' => $subscription->plan]);

            return $subscription;
        });
    }

    /**
     * Record what the provider reports (e.g. from a webhook, later). Idempotent; a provider
     * subscription can never move to another organization.
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

            return $existing !== null ? $this->apply($existing, $remote) : $this->store($organization, $provider, $remote);
        });
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
     * @param  \Closure(Subscription): Subscription  $callback
     *
     * @throws BillingException
     */
    private function withCurrent(Organization $organization, \Closure $callback): Subscription
    {
        return DB::transaction(function () use ($organization, $callback) {
            $subscription = Subscription::query()->where('organization_id', $organization->id)->current()->lockForUpdate()->latest('id')->first()
                ?? throw new BillingException('This business has no subscription.');

            return $callback($subscription);
        });
    }

    private function customerId(Organization $organization, BillingProviderInterface $provider): string
    {
        if ($organization->billing_customer_id !== null && $organization->billing_provider === $provider->name()) {
            return $organization->billing_customer_id;
        }

        $customerId = $provider->createCustomer($organization);
        $organization->forceFill(['billing_provider' => $provider->name(), 'billing_customer_id' => $customerId])->save();

        return $customerId;
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
        $subscription->forceFill($remote->toAttributes())->save();

        return $subscription;
    }

    /**
     * @return array<string, Plan>
     */
    public function plans(): array
    {
        return $this->plans->all();
    }
}

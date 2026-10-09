<?php

namespace App\Services\Billing;

use App\Billing\CheckoutSession;
use App\Billing\InvoiceSummary;
use App\Billing\PaymentMethodSummary;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Contracts\Billing\BillingProviderInterface;
use App\Enums\Team\NotificationType;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        private readonly BillingLifecycle $lifecycle,
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
     * Give a new business its one card-free trial (idempotent). Called when the business signs up.
     */
    public function startSignupTrial(Organization $organization): void
    {
        $days = (int) config('billing.trial_days');

        if ($days <= 0 || $organization->trial_used_at !== null) {
            return;
        }

        $this->plans->get((string) config('billing.signup_trial_plan'));
        $organization->forceFill(['trial_used_at' => now(), 'trial_ends_at' => now()->addDays($days)])->save();
        OrganizationActivity::record($organization, 'trial_started', null, ['plan' => config('billing.signup_trial_plan'), 'days' => $days]);
    }

    /**
     * Remind owners whose card-free trial ends soon and who haven't subscribed (once per trial).
     *
     * @return int Reminders sent.
     */
    public function sendTrialReminders(): int
    {
        $sent = 0;
        $team = app(TeamDirectory::class);
        $notifier = app(TeamNotifier::class);

        Organization::query()->whereNull('trial_reminder_sent_at')->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays(max(1, (int) config('billing.trial_reminder_days'))))
            ->orderBy('id')->each(function (Organization $organization) use ($team, $notifier, &$sent) {
                if ($this->currentSubscription($organization) !== null) {
                    return;
                }

                $claimed = Organization::query()->whereKey($organization->id)->whereNull('trial_reminder_sent_at')->update(['trial_reminder_sent_at' => now()]);
                $owner = $team->owner($organization->id);

                if ($claimed === 0 || $owner === null) {
                    return;
                }

                $days = max(1, (int) ceil(now()->floatDiffInDays($organization->trial_ends_at)));
                $notifier->notify($organization, NotificationType::TrialEnding, [$owner],
                    "Your free trial ends in {$days} ".Str::plural('day', $days).'. Choose a plan to keep your limits; otherwise you move to the Free plan (nothing is deleted).',
                    route('settings.billing'), "trial:{$organization->id}:{$organization->trial_ends_at->timestamp}");
                $sent++;
            });

        return $sent;
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

        Log::info('Checkout started.', ['event' => 'billing.checkout.started', 'organization_id' => $organization->id, 'plan' => $plan->key, 'provider' => $provider->name()]);

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
            Log::warning('Checkout completion rejected: it belongs to another organization.', ['event' => 'billing.checkout.rejected', 'organization_id' => $organization->id]);

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
        Log::info('Subscription started.', ['event' => 'billing.subscription.started', 'organization_id' => $organization->id, 'subscription_id' => $subscription->id, 'plan' => $plan->key]);

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

            $subscription = $this->apply($subscription, $provider->cancelScheduledChange($subscription), $actor);
            OrganizationActivity::record($organization, 'subscription_change_cancelled', $actor, ['plan' => $plan->key]);

            return $subscription;
        }

        if ($current === null || $plan->priceCents > $current->priceCents) {
            $subscription = $this->apply($subscription, $provider->changePlan($subscription, $plan), $actor);
            OrganizationActivity::record($organization, 'subscription_plan_changed', $actor, ['changes' => ['Plan' => ['from' => $from, 'to' => $plan->key]]]);

            return $subscription;
        }

        $at = $subscription->current_period_end ?? $subscription->trial_ends_at
            ?? throw new BillingException('The current billing period end is unknown. Please try again later.');
        $subscription = $this->apply($subscription, $provider->scheduleChange($subscription, $plan, CarbonImmutable::instance($at)), $actor);
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

        $subscription = $this->apply($subscription, $this->providerFor($subscription)->cancel($subscription, $atPeriodEnd), $actor);
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

        $subscription = $this->apply($subscription, $this->providerFor($subscription)->resume($subscription), $actor);
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
     * The saved card in safe terms (brand, last four, expiry), or null when there is none.
     * Briefly cached so page refreshes don't call the provider each time.
     *
     * @throws AuthorizationException|BillingException
     */
    public function paymentMethod(User $actor): ?PaymentMethodSummary
    {
        $customerId = $this->billingCustomerFor($actor);

        if ($customerId === null) {
            return null;
        }

        // false stands for "no card", so that answer is cached too.
        $card = Cache::remember($this->cacheKey('payment-method', $customerId), 300, fn () => $this->provider()->paymentMethod($customerId) ?? false);

        return $card ?: null;
    }

    /**
     * Past invoices of the actor's own organization, newest first.
     *
     * @return list<InvoiceSummary>
     *
     * @throws AuthorizationException|BillingException
     */
    public function invoices(User $actor): array
    {
        $customerId = $this->billingCustomerFor($actor);

        return $customerId === null ? [] : Cache::remember($this->cacheKey('invoices', $customerId), 300, fn () => $this->provider()->invoices($customerId));
    }

    /**
     * Forget what was cached from the provider (after the customer used its billing pages).
     */
    public function forgetProviderCache(Organization $organization): void
    {
        if ($organization->billing_customer_id !== null) {
            Cache::forget($this->cacheKey('payment-method', $organization->billing_customer_id));
            Cache::forget($this->cacheKey('invoices', $organization->billing_customer_id));
        }
    }

    private function billingCustomerFor(User $actor): ?string
    {
        $organization = $this->authorize($actor);

        return $organization->billing_customer_id !== null && $organization->billing_provider === $this->provider()->name() ? $organization->billing_customer_id : null;
    }

    private function cacheKey(string $what, string $customerId): string
    {
        return "billing:{$this->provider()->name()}:{$what}:{$customerId}";
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
     * The organization a provider customer belongs to (null when it isn't one of ours).
     */
    public function organizationForCustomer(string $provider, ?string $customerId): ?Organization
    {
        return blank($customerId) ? null : Organization::query()->where('billing_provider', $provider)->where('billing_customer_id', $customerId)->first();
    }

    /**
     * A webhook said this subscription changed: read its current state from the provider (so
     * out-of-order or repeated events can't leave stale data) and record it. A subscription that
     * is new to us is recorded once, in the activity log too.
     *
     * @throws BillingException
     */
    public function syncFromProvider(Organization $organization, string $providerSubscriptionId, ?string $event = null): Subscription
    {
        $provider = $this->provider();
        $before = Subscription::query()->where('provider', $provider->name())->where('provider_subscription_id', $providerSubscriptionId)->first();
        $subscription = $this->sync($organization, $provider->name(), $provider->fetchSubscription($providerSubscriptionId));

        if ($before === null) {
            OrganizationActivity::record($organization, 'subscription_started', null, ['plan' => $subscription->plan, 'status' => $subscription->status->value, 'trial' => $subscription->trial_ends_at !== null, 'via' => 'webhook']);
        } elseif ($before->status !== $subscription->status) {
            OrganizationActivity::record($organization, 'subscription_status_changed', null, ['from' => $before->status->value, 'to' => $subscription->status->value, 'via' => 'webhook', 'event' => $event]);
        }

        return $subscription;
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
                    Log::warning('Subscription sync failed.', ['event' => 'billing.sync.failed', 'subscription_id' => $subscription->id, 'organization_id' => $subscription->organization_id]);
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
                Log::warning('Billing sync rejected: subscription belongs to another organization.', ['event' => 'billing.sync.rejected', 'subscription_id' => $existing->id, 'organization_id' => $organization->id]);

                throw new BillingException('This subscription belongs to another organization.');
            }

            $subscription = $existing !== null ? $this->apply($existing, $remote) : $this->store($organization, $provider, $remote);

            // A business gets one free trial, ever; a subscription ends the sign-up trial (it converted).
            Organization::query()->whereKey($organization->id)->when($remote->trialEndsAt !== null, fn ($q) => $q->whereNull('trial_used_at'))
                ->update(array_filter(['trial_used_at' => $remote->trialEndsAt !== null ? now() : null]));
            Organization::query()->whereKey($organization->id)->where('trial_ends_at', '>', now())->update(['trial_ends_at' => now()]);
            $organization->refresh();

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
        $subscription->forceFill(['organization_id' => $organization->id, 'provider' => $provider] + $remote->toAttributes() + $this->lifecycle->graceAttributes(null, $remote->status))->save();

        return $subscription;
    }

    /**
     * Save the provider's state and let the lifecycle react to what changed (audit, notifications, grace period).
     */
    private function apply(Subscription $subscription, ProviderSubscription $remote, ?User $actor = null): Subscription
    {
        $this->plans->get($remote->planKey);
        $before = $this->lifecycle->snapshot($subscription);
        $subscription->forceFill($remote->toAttributes() + $this->lifecycle->graceAttributes($subscription, $remote->status))->save();
        $this->lifecycle->transition($subscription->organization, $before, $subscription, $actor);

        return $subscription;
    }

    /**
     * Re-read one subscription from its provider (used by the scheduled grace-period check).
     *
     * @throws BillingException
     */
    public function refreshSubscription(Subscription $subscription): Subscription
    {
        return $this->apply($subscription, $this->providerFor($subscription)->retrieveSubscription($subscription));
    }
}

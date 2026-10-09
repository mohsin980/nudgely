<?php

namespace App\Services\Billing\Providers;

use App\Billing\CheckoutSession;
use App\Billing\CompletedCheckout;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Contracts\Billing\BillingProviderInterface;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe through its REST API (no SDK). Customers pay on Stripe Checkout and manage cards,
 * billing details and invoices in the Stripe billing portal: no card data touches QuoteFollow.
 *
 * Upgrades switch the price now with proration; downgrades use a subscription schedule so the
 * cheaper price starts at the end of the paid period.
 */
class StripeBillingProvider implements BillingProviderInterface
{
    private const STATUSES = [
        'trialing' => SubscriptionStatus::Trialing,
        'active' => SubscriptionStatus::Active,
        'past_due' => SubscriptionStatus::PastDue,
        'paused' => SubscriptionStatus::Paused,
        'canceled' => SubscriptionStatus::Cancelled,
        'incomplete' => SubscriptionStatus::Incomplete,
        'incomplete_expired' => SubscriptionStatus::Expired,
        'unpaid' => SubscriptionStatus::Unpaid,
    ];

    /**
     * @param  array{secret?: string|null, api_base?: string, timeout?: int}  $config  config('services.stripe')
     */
    public function __construct(
        private readonly array $config,
        private readonly PlanCatalog $plans,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCustomer(Organization $organization): string
    {
        $customer = $this->send('post', 'customers', array_filter([
            'name' => $organization->name,
            'email' => $organization->email ?: $organization->users()->where('role', 'owner')->value('email'),
            'metadata' => ['organization_id' => (string) $organization->id],
        ]), idempotencyKey: "quotefollow-customer-{$organization->id}");

        return (string) $customer['id'];
    }

    public function createCheckout(Organization $organization, string $customerId, Plan $plan, int $trialDays, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $subscriptionData = ['metadata' => ['organization_id' => (string) $organization->id, 'plan' => $plan->key]];

        if ($trialDays > 0) {
            $subscriptionData['trial_period_days'] = $trialDays;
        }

        $session = $this->send('post', 'checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $organization->id,
            'line_items' => [['price' => $this->priceId($plan), 'quantity' => 1]],
            'subscription_data' => $subscriptionData,
            'metadata' => ['organization_id' => (string) $organization->id, 'plan' => $plan->key],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return new CheckoutSession((string) $session['id'], (string) $session['url']);
    }

    public function completedCheckout(string $sessionId): ?CompletedCheckout
    {
        $session = $this->send('get', 'checkout/sessions/'.rawurlencode($sessionId), ['expand' => ['subscription']]);

        if (($session['status'] ?? null) !== 'complete' || ! is_array($session['subscription'] ?? null)) {
            return null;
        }

        $organizationId = $session['metadata']['organization_id'] ?? $session['client_reference_id'] ?? null;

        return new CompletedCheckout(
            (string) $session['id'],
            is_string($session['customer'] ?? null) ? $session['customer'] : ($session['customer']['id'] ?? null),
            ctype_digit((string) $organizationId) ? (int) $organizationId : null,
            $this->map($session['subscription']),
        );
    }

    public function createSubscription(string $customerId, Plan $plan, int $trialDays = 0): ProviderSubscription
    {
        throw new BillingException('Stripe subscriptions start through checkout.');
    }

    public function changePlan(Subscription $subscription, Plan $plan): ProviderSubscription
    {
        $this->releaseSchedule($subscription);
        $remote = $this->subscription($subscription);

        $updated = $this->send('post', 'subscriptions/'.rawurlencode($subscription->provider_subscription_id), [
            'items' => [['id' => $remote['items']['data'][0]['id'], 'price' => $this->priceId($plan)]],
            'proration_behavior' => 'create_prorations',
            'cancel_at_period_end' => 'false',
        ], idempotencyKey: "quotefollow-change-{$subscription->id}-{$plan->key}-".($remote['items']['data'][0]['price']['id'] ?? ''));

        return $this->map($updated);
    }

    public function scheduleChange(Subscription $subscription, Plan $plan, CarbonImmutable $at): ProviderSubscription
    {
        $remote = $this->subscription($subscription);
        $scheduleId = is_string($remote['schedule'] ?? null) ? $remote['schedule'] : ($remote['schedule']['id'] ?? null);
        $scheduleId ??= $this->send('post', 'subscription_schedules', ['from_subscription' => $remote['id']])['id'];
        $currentPrice = $remote['items']['data'][0]['price']['id'];

        $this->send('post', 'subscription_schedules/'.rawurlencode($scheduleId), [
            'end_behavior' => 'release',
            'proration_behavior' => 'none',
            'phases' => [
                ['items' => [['price' => $currentPrice, 'quantity' => 1]], 'start_date' => $this->periodStart($remote), 'end_date' => $at->getTimestamp()],
                ['items' => [['price' => $this->priceId($plan), 'quantity' => 1]], 'start_date' => $at->getTimestamp(), 'iterations' => 1],
            ],
        ]);

        return $this->map($this->subscription($subscription));
    }

    public function cancelScheduledChange(Subscription $subscription): ProviderSubscription
    {
        $this->releaseSchedule($subscription);

        return $this->map($this->subscription($subscription));
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): ProviderSubscription
    {
        $this->releaseSchedule($subscription);
        $path = 'subscriptions/'.rawurlencode($subscription->provider_subscription_id);

        return $this->map($atPeriodEnd
            ? $this->send('post', $path, ['cancel_at_period_end' => 'true'])
            : $this->send('delete', $path));
    }

    public function resume(Subscription $subscription): ProviderSubscription
    {
        return $this->map($this->send('post', 'subscriptions/'.rawurlencode($subscription->provider_subscription_id), ['cancel_at_period_end' => 'false']));
    }

    public function retrieveSubscription(Subscription $subscription): ProviderSubscription
    {
        return $this->map($this->subscription($subscription));
    }

    public function fetchSubscription(string $providerSubscriptionId): ProviderSubscription
    {
        return $this->map($this->send('get', 'subscriptions/'.rawurlencode($providerSubscriptionId), ['expand' => ['schedule']]));
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        return (string) $this->send('post', 'billing_portal/sessions', ['customer' => $customerId, 'return_url' => $returnUrl])['url'];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(Subscription $subscription): array
    {
        return $this->send('get', 'subscriptions/'.rawurlencode($subscription->provider_subscription_id), ['expand' => ['schedule']]);
    }

    private function releaseSchedule(Subscription $subscription): void
    {
        $scheduleId = $subscription->provider_schedule_id ?? $this->scheduleIdOf($this->subscription($subscription));

        if ($scheduleId !== null) {
            $this->send('post', 'subscription_schedules/'.rawurlencode($scheduleId).'/release');
        }
    }

    /**
     * Stripe's subscription object in QuoteFollow's terms, including a pending scheduled change.
     *
     * @param  array<string, mixed>  $s
     */
    private function map(array $s): ProviderSubscription
    {
        $item = $s['items']['data'][0] ?? [];
        $time = fn ($value) => is_int($value) ? CarbonImmutable::createFromTimestampUTC($value) : null;
        $plan = $this->plans->findByProviderPriceId($item['price']['id'] ?? null)
            ?? throw new BillingException('This subscription uses a price QuoteFollow doesn\'t know.');

        [$scheduledPlan, $scheduledAt] = $this->scheduledChange($s, $plan);

        return new ProviderSubscription(
            id: (string) $s['id'],
            planKey: $plan->key,
            status: self::STATUSES[$s['status'] ?? ''] ?? SubscriptionStatus::Incomplete,
            trialEndsAt: $time($s['trial_end'] ?? null),
            // Newer API versions keep the period on the subscription item.
            currentPeriodStart: $time($s['current_period_start'] ?? $item['current_period_start'] ?? null),
            currentPeriodEnd: $time($s['current_period_end'] ?? $item['current_period_end'] ?? null),
            cancelAtPeriodEnd: (bool) ($s['cancel_at_period_end'] ?? false),
            canceledAt: $time($s['canceled_at'] ?? null),
            endedAt: $time($s['ended_at'] ?? null),
            scheduledPlanKey: $scheduledPlan,
            scheduledChangeAt: $scheduledAt,
            scheduleId: $scheduledPlan === null ? null : $this->scheduleIdOf($s),
        );
    }

    /**
     * The next phase of an expanded schedule, when it switches to another plan.
     *
     * @param  array<string, mixed>  $s
     * @return array{0: ?string, 1: ?CarbonImmutable}
     */
    private function scheduledChange(array $s, Plan $current): array
    {
        $schedule = $s['schedule'] ?? null;

        if (! is_array($schedule)) {
            return [null, null];
        }

        $now = CarbonImmutable::now()->getTimestamp();

        foreach ($schedule['phases'] ?? [] as $phase) {
            $plan = $this->plans->findByProviderPriceId($phase['items'][0]['price'] ?? null);

            if ($plan !== null && $plan->key !== $current->key && ($phase['start_date'] ?? 0) > $now) {
                return [$plan->key, CarbonImmutable::createFromTimestampUTC($phase['start_date'])];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function scheduleIdOf(array $s): ?string
    {
        $schedule = $s['schedule'] ?? null;

        return is_string($schedule) ? $schedule : ($schedule['id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function periodStart(array $s): int
    {
        return (int) ($s['current_period_start'] ?? $s['items']['data'][0]['current_period_start'] ?? CarbonImmutable::now()->getTimestamp());
    }

    private function priceId(Plan $plan): string
    {
        return $plan->providerPriceId ?: throw new BillingException("The {$plan->name} plan isn't available for purchase yet.");
    }

    /**
     * One API call. Failures are logged without secrets and turned into a message safe to show.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws BillingException
     */
    private function send(string $method, string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        try {
            $response = $this->client($idempotencyKey)->{$method}($path, $data);
        } catch (ConnectionException) {
            Log::warning('Stripe request failed: connection error.', ['path' => $this->logPath($path)]);

            throw new BillingException('The payment provider can\'t be reached right now. Please try again in a few minutes.');
        }

        if ($response->failed()) {
            Log::warning('Stripe request failed.', ['path' => $this->logPath($path), 'status' => $response->status(),
                'type' => $response->json('error.type'), 'code' => $response->json('error.code')]);

            throw new BillingException($response->status() >= 500 || $response->status() === 429
                ? 'The payment provider is having trouble right now. Please try again in a few minutes.'
                : 'The payment provider rejected the request. Please try again or contact support.');
        }

        return (array) $response->json();
    }

    private function client(?string $idempotencyKey): PendingRequest
    {
        $secret = $this->config['secret'] ?? null;

        if (blank($secret)) {
            throw new BillingException('Online payments aren\'t set up yet.');
        }

        return Http::baseUrl(rtrim($this->config['api_base'] ?? 'https://api.stripe.com/v1', '/'))
            ->withToken($secret)
            ->asForm()
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->when($idempotencyKey !== null, fn (PendingRequest $r) => $r->withHeaders(['Idempotency-Key' => $idempotencyKey]));
    }

    /**
     * Object IDs are left out of logs' path field.
     */
    private function logPath(string $path): string
    {
        return preg_replace('/\/[a-z]+_[A-Za-z0-9]+/', '/{id}', $path);
    }
}

<?php

namespace App\Services\Platform;

use App\Exceptions\Billing\BillingException;
use App\Exceptions\Platform\SubscriptionRefreshException;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Platform\Concerns\AuditsDeniedAttempts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Re-reads one subscription from the billing provider, through the existing BillingService (the same code the hourly
 * sync uses). The provider stays the source of truth: this never sets a status itself, cannot cancel, refund or change
 * a plan, and can only make the stored record match what the provider reports.
 */
class SubscriptionRefresh
{
    use AuditsDeniedAttempts;

    public function __construct(private readonly BillingService $billing) {}

    /**
     * @throws AuthorizationException
     * @throws SubscriptionRefreshException
     */
    public function refresh(Subscription $subscription, User $actor): Subscription
    {
        $this->authorizeAudited($actor, 'manage_subscriptions', [], 'subscription_refreshed', ['type' => 'subscription', 'id' => $subscription->id, 'organization_id' => $subscription->organization_id]);

        // The panel loads a trimmed record; billing needs the whole row and organization.
        $subscription = Subscription::query()->findOrFail($subscription->id);

        if ($subscription->provider === 'manual') {
            throw SubscriptionRefreshException::localOnly();
        }

        // Protect the provider's API rate limit from repeated clicks.
        if (! Cache::add("admin:subscription-refresh:{$subscription->id}", 1, 30)) {
            throw SubscriptionRefreshException::tooSoon();
        }

        $before = ['status' => $subscription->status->value, 'plan' => $subscription->plan];

        try {
            $fresh = $this->billing->refreshSubscription($subscription);
        } catch (BillingException $e) {
            Log::warning('Admin subscription refresh failed.', ['event' => 'billing.admin_refresh.failed', 'subscription_id' => $subscription->id, 'actor_id' => $actor->id]);

            PlatformAuditLog::record('subscription_refreshed', $actor, $subscription->organization_id, null, null, ['subscription_id' => $subscription->id], 'subscription', $subscription->id, PlatformAuditLog::FAILED);

            throw SubscriptionRefreshException::providerFailed();
        }

        $after = ['status' => $fresh->status->value, 'plan' => $fresh->plan];
        $changed = [];

        foreach ($before as $field => $from) {
            if ($from !== $after[$field]) {
                $changed[$field] = ['from' => $from, 'to' => $after[$field]];
            }
        }

        PlatformAuditLog::record('subscription_refreshed', $actor, $subscription->organization_id, null, null, ['subscription_id' => $subscription->id, 'changed' => $changed], 'subscription', $subscription->id);

        return $fresh;
    }
}

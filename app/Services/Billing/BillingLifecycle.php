<?php

namespace App\Services\Billing;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Team\NotificationType;
use App\Exceptions\Billing\BillingException;
use App\Jobs\Billing\NotifyOwnerJob;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Everything that follows from a billing state changing: audit entries, owner notifications,
 * the grace period after a failed payment, and the scheduled checks (trial expiry, grace expiry).
 *
 * Every provider-driven change (webhook, hourly sync, return from the billing portal) goes
 * through BillingService::apply(), which calls transition() with the state before and after, so
 * the same rules apply however a change arrives. Everything is idempotent: audit and
 * notifications fire on a change of state (not on every event), notifications are keyed, and the
 * scheduled checks claim each organization/subscription once. No business data is ever deleted.
 */
class BillingLifecycle
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * The fields transition() compares.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Subscription $subscription): array
    {
        return [
            'status' => $subscription->status,
            'plan' => $subscription->plan,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'current_period_start' => $subscription->current_period_start,
            'past_due_since' => $subscription->past_due_since,
            'restricted_at' => $subscription->restricted_at,
        ];
    }

    /**
     * Grace-period bookkeeping to store with the provider's state: when payment first failed, and
     * whether a restriction is in force (unpaid). Recovery clears both.
     *
     * @return array{past_due_since: mixed, restricted_at: mixed}
     */
    public function graceAttributes(?Subscription $existing, SubscriptionStatus $status): array
    {
        return match ($status) {
            SubscriptionStatus::PastDue => ['past_due_since' => $existing?->past_due_since ?? now(), 'restricted_at' => $existing?->restricted_at],
            SubscriptionStatus::Unpaid => ['past_due_since' => $existing?->past_due_since ?? now(), 'restricted_at' => $existing?->restricted_at ?? now()],
            default => ['past_due_since' => null, 'restricted_at' => null],
        };
    }

    /**
     * Record and announce what changed between two states of the same subscription.
     *
     * @param  array<string, mixed>  $before  from snapshot()
     * @param  User|null  $actor  Set when a person caused the change, who then already has their own audit entry for cancel/resume/plan changes.
     */
    public function transition(Organization $organization, array $before, Subscription $after, ?User $actor = null): void
    {
        $was = $before['status'];
        $now = $after->status;
        $plan = $after->planDefinition()?->name ?? ucfirst($after->plan);
        $via = $actor === null ? 'provider' : 'owner';

        if ($now === SubscriptionStatus::PastDue && $was !== SubscriptionStatus::PastDue) {
            $until = $after->graceEndsAt();
            OrganizationActivity::record($organization, 'payment_failed', $actor, ['plan' => $after->plan, 'grace_ends_at' => $until?->toIso8601String()]);
            $this->notify($organization, NotificationType::PaymentFailed,
                "We couldn't process your payment. Update your payment method".($until ? " by {$organization->formatDate($until)}" : '')." to keep your {$plan} plan.",
                route('settings.billing.payment-method'), 'Update Payment Method', "past-due:{$after->id}:{$after->past_due_since?->timestamp}");
        }

        if (in_array($was, [SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid], true) && in_array($now, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            OrganizationActivity::record($organization, 'payment_recovered', $actor, ['plan' => $after->plan]);
            $this->notify($organization, NotificationType::PaymentRecovered, 'Your payment was successful and your account is active again.',
                route('settings.billing'), 'View Billing', "recovered:{$after->id}:{$before['past_due_since']?->timestamp}");

            if ($before['restricted_at'] !== null) {
                OrganizationActivity::record($organization, 'billing_restriction_lifted', $actor, ['plan' => $after->plan]);
            }
        }

        if ($now === SubscriptionStatus::Unpaid && $was !== SubscriptionStatus::Unpaid) {
            $this->restrict($organization, $after, 'unpaid', "key:{$after->restricted_at?->timestamp}");
        }

        if ($was === SubscriptionStatus::Trialing && $now === SubscriptionStatus::Active) {
            OrganizationActivity::record($organization, 'trial_converted', $actor, ['plan' => $after->plan]);
            $this->notify($organization, NotificationType::SubscriptionRenewed, "Your trial has ended and your {$plan} subscription is now active.",
                route('settings.billing'), 'View Billing', "converted:{$after->id}");
        } elseif ($was === SubscriptionStatus::Active && $now === SubscriptionStatus::Active && $before['current_period_start'] !== null
            && $after->current_period_start?->gt($before['current_period_start'])) {
            $next = $after->current_period_end ? " Next renewal: {$organization->formatDate($after->current_period_end)}." : '';
            $this->notify($organization, NotificationType::SubscriptionRenewed, "Your {$plan} plan renewed.{$next}",
                route('settings.billing'), 'View Billing', "renewal:{$after->id}:{$after->current_period_start->timestamp}");
        }

        if (! $before['cancel_at_period_end'] && $after->cancel_at_period_end && ! $now->isTerminal()) {
            if ($actor === null) {
                OrganizationActivity::record($organization, 'subscription_cancelled', null, ['plan' => $after->plan, 'at_period_end' => true, 'via' => $via]);
            }

            $date = $after->current_period_end ? $organization->formatDate($after->current_period_end) : 'the end of the billing period';
            $this->notify($organization, NotificationType::SubscriptionCancelling,
                "Your subscription cancellation is scheduled. Your plan stays active until {$date}, then you move to the Free plan. Nothing is deleted.",
                route('settings.billing'), 'Resume Subscription', "cancel:{$after->id}:{$after->current_period_end?->timestamp}");
        } elseif ($before['cancel_at_period_end'] && ! $after->cancel_at_period_end && ! $now->isTerminal() && $actor === null) {
            OrganizationActivity::record($organization, 'subscription_resumed', null, ['plan' => $after->plan, 'via' => $via]);
        }

        if ($now->isTerminal() && ! $was->isTerminal()) {
            OrganizationActivity::record($organization, 'subscription_ended', $actor, ['plan' => $after->plan, 'via' => $via]);
            $this->notify($organization, NotificationType::SubscriptionEnded, "Your {$plan} subscription has ended. You're on the Free plan now, and nothing was deleted.",
                route('settings.billing.plans'), 'Choose a Plan', "ended:{$after->id}");
        }

        if ($actor === null && $before['plan'] !== $after->plan) {
            OrganizationActivity::record($organization, 'subscription_plan_changed', null, ['changes' => ['Plan' => ['from' => $before['plan'], 'to' => $after->plan]], 'via' => $via]);
        }
    }

    /**
     * Sign-up trials that ran out: note it once, tell the owner, and record the move to Free limits.
     * Nothing is deleted; the business simply gets the default plan's limits.
     *
     * @return int Organizations processed.
     */
    public function expireTrials(): int
    {
        $count = 0;

        Organization::query()->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now())->whereNull('trial_expired_at')
            ->whereDoesntHave('subscriptions', fn ($q) => $q->current())
            ->orderBy('id')->each(function (Organization $organization) use (&$count) {
                $claimed = DB::transaction(function () use ($organization) {
                    $locked = Organization::query()->lockForUpdate()->find($organization->id);

                    if ($locked === null || $locked->trial_expired_at !== null) {
                        return false;
                    }

                    $locked->forceFill(['trial_expired_at' => now()])->save();

                    return true;
                });

                if (! $claimed) {
                    return;
                }

                $count++;
                $free = app(PlanCatalog::class)->default();
                $over = $this->overLimits($organization, $free);
                OrganizationActivity::record($organization, 'trial_expired', null, ['plan' => config('billing.signup_trial_plan')]);
                OrganizationActivity::record($organization, 'billing_restriction_applied', null, ['reason' => 'trial_expired', 'plan' => $free->key, 'over_limits' => $over]);
                $this->notify($organization, NotificationType::TrialEnded,
                    'Your QuoteFollow trial has ended. You are on the Free plan now, and nothing was deleted. Choose a plan to get your limits back.',
                    route('settings.billing.plans'), 'Choose a Plan', "trial-ended:{$organization->id}:{$organization->trial_ends_at->timestamp}");
                Log::info('Trial expired.', ['organization_id' => $organization->id]);
            });

        return $count;
    }

    /**
     * Past-due subscriptions whose grace period is over: first re-check with the provider (payment may
     * have gone through), and if still unpaid apply the restriction once.
     *
     * @return array{restricted: int, recovered: int, failed: int}
     */
    public function checkGracePeriods(): array
    {
        $result = ['restricted' => 0, 'recovered' => 0, 'failed' => 0];
        $deadline = now()->subDays(max(0, (int) config('billing.grace_days')));

        Subscription::query()->where('status', SubscriptionStatus::PastDue)->whereNotNull('past_due_since')->where('past_due_since', '<=', $deadline)->whereNull('restricted_at')
            ->orderBy('id')->each(function (Subscription $subscription) use (&$result) {
                try {
                    $subscription = app(BillingService::class)->refreshSubscription($subscription);
                } catch (BillingException) {
                    $result['failed']++;
                }

                if ($subscription->status !== SubscriptionStatus::PastDue) {
                    $result['recovered']++;

                    return;
                }

                $claimed = Subscription::query()->whereKey($subscription->id)->where('status', SubscriptionStatus::PastDue)->whereNull('restricted_at')->update(['restricted_at' => now()]);

                if ($claimed === 1) {
                    $result['restricted']++;
                    $subscription->refresh();
                    $this->restrict($subscription->organization, $subscription, 'grace_expired', "grace:{$subscription->past_due_since?->timestamp}");
                }
            });

        return $result;
    }

    private function restrict(Organization $organization, Subscription $subscription, string $reason, string $keySuffix): void
    {
        $free = app(PlanCatalog::class)->default();
        OrganizationActivity::record($organization, 'billing_restriction_applied', null, ['reason' => $reason, 'plan' => $free->key, 'over_limits' => $this->overLimits($organization, $free)]);
        $this->notify($organization, NotificationType::BillingRestricted,
            "Your payment is still overdue, so your plan's paid features are paused and Free plan limits apply. Update your payment method to restore your plan. Your data is safe.",
            route('settings.billing.payment-method'), 'Update Payment Method', "restricted:{$subscription->id}:{$keySuffix}");
    }

    /**
     * Limits the business already exceeds on the restricted plan (information for the audit; nothing is removed).
     *
     * @return list<string>
     */
    private function overLimits(Organization $organization, Plan $plan): array
    {
        $over = [];

        foreach ($this->entitlements->summary($organization) as $key => $row) {
            $limit = $plan->limit(LimitKey::from($key));

            if ($limit !== null && $row['used'] > $limit) {
                $over[] = "{$key}: {$row['used']}/{$limit}";
            }
        }

        return $over;
    }

    private function notify(Organization $organization, NotificationType $type, string $message, string $url, string $action, string $key): void
    {
        NotifyOwnerJob::dispatch($organization->id, $type->value, $message, $url, $key, $action);
    }
}

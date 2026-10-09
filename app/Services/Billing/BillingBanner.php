<?php

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * The one billing banner (if any) the owner sees across the app: payment problem, restriction,
 * trial ending soon, cancellation scheduled, or an ended trial/subscription. Normal active
 * subscriptions show nothing. Two small indexed queries and no provider calls, so it is cheap
 * enough for every page; all slow work happens in the scheduled jobs and webhooks.
 */
class BillingBanner
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @return array{kind: string, tone: string, message: string, action: string, url: string}|null
     */
    public function for(Organization $organization): ?array
    {
        $subscription = Subscription::query()->where('organization_id', $organization->id)->latest('id')->first();

        if ($subscription !== null && ! $subscription->status->isTerminal()) {
            return $this->forCurrent($organization, $subscription);
        }

        return $this->forNoSubscription($organization, $subscription);
    }

    /**
     * @return array{kind: string, tone: string, message: string, action: string, url: string}|null
     */
    private function forCurrent(Organization $organization, Subscription $subscription): ?array
    {
        $plan = $subscription->planDefinition()?->name ?? ucfirst($subscription->plan);

        if ($subscription->status === SubscriptionStatus::Unpaid || $subscription->restricted_at !== null || ($subscription->status === SubscriptionStatus::PastDue && ! $subscription->inGracePeriod())) {
            return $this->banner('restricted', 'danger', "Your payment is overdue, so your {$plan} plan is paused and Free plan limits apply. Your data is safe. Update your payment method to restore it.", 'Update Payment Method', route('settings.billing.payment-method'));
        }

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $until = $subscription->graceEndsAt();

            return $this->banner('past_due', 'warning', "We couldn't process your payment. Update your payment method".($until ? " by {$organization->formatDate($until)}" : '')." to keep your {$plan} plan.", 'Update Payment Method', route('settings.billing.payment-method'));
        }

        if ($subscription->cancel_at_period_end) {
            $date = $subscription->current_period_end ? $organization->formatDate($subscription->current_period_end) : 'the end of the billing period';

            return $this->banner('cancel_scheduled', 'info', "Your subscription ends on {$date}. After that you move to the Free plan; nothing is deleted.", 'Resume Subscription', route('settings.billing'));
        }

        $soon = max(1, (int) config('billing.trial_banner_days'));

        if ($subscription->onTrial() && $subscription->trial_ends_at->lte(now()->addDays($soon))) {
            return $this->banner('trial_ending', 'info', "Your {$plan} trial ends on {$organization->formatDate($subscription->trial_ends_at)}. Your subscription continues after that unless you cancel.", 'Manage Billing', route('settings.billing'));
        }

        return null;
    }

    /**
     * @return array{kind: string, tone: string, message: string, action: string, url: string}|null
     */
    private function forNoSubscription(Organization $organization, ?Subscription $ended): ?array
    {
        $trial = $this->entitlements->trial($organization);

        if ($trial !== null) {
            if ($trial['ends_at']->gt(now()->addDays(max(1, (int) config('billing.trial_banner_days'))))) {
                return null;
            }

            $days = $trial['days_left'];

            return $this->banner('trial_ending', 'info', "Your free {$trial['plan']->name} trial ends in {$days} ".Str::plural('day', $days).'. Choose a plan to keep your limits; otherwise you move to Free and nothing is deleted.', 'Choose a Plan', route('settings.billing.plans'));
        }

        $window = now()->subDays(max(1, (int) config('billing.ended_banner_days')));

        if ($ended !== null && $ended->status->isTerminal() && ($ended->ended_at ?? $ended->updated_at)?->gte($window)) {
            return $this->banner('expired', 'warning', 'Your subscription has ended. You are on the Free plan, and nothing was deleted.', 'Choose a Plan', route('settings.billing.plans'));
        }

        if ($ended === null && $organization->trial_expired_at !== null && $organization->trial_expired_at->gte($window)) {
            return $this->banner('expired', 'warning', 'Your QuoteFollow trial has ended. You are on the Free plan, and nothing was deleted.', 'Choose a Plan', route('settings.billing.plans'));
        }

        return null;
    }

    /**
     * @return array{kind: string, tone: string, message: string, action: string, url: string}
     */
    private function banner(string $kind, string $tone, string $message, string $action, string $url): array
    {
        return compact('kind', 'tone', 'message', 'action', 'url');
    }
}

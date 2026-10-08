<?php

namespace App\Enums\Team;

/**
 * Things a team member can be notified about, each in the app and/or by email.
 */
enum NotificationType: string
{
    case CustomerReply = 'customer_reply';
    case EstimateAccepted = 'estimate_accepted';
    case EstimateDeclined = 'estimate_declined';
    case AutomationFailed = 'automation_failed';
    case FollowUpDue = 'follow_up_due';
    case TaskAssigned = 'task_assigned';
    case TrialEnding = 'trial_ending';
    case PaymentFailed = 'payment_failed';
    case PaymentRecovered = 'payment_recovered';
    case TrialEnded = 'trial_ended';
    case SubscriptionCancelling = 'subscription_cancelling';
    case SubscriptionEnded = 'subscription_ended';
    case SubscriptionRenewed = 'subscription_renewed';
    case BillingRestricted = 'billing_restricted';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReply => 'New customer reply',
            self::EstimateAccepted => 'Estimate accepted',
            self::EstimateDeclined => 'Estimate declined',
            self::AutomationFailed => 'Automation failure',
            self::FollowUpDue => 'Follow-up due',
            self::TaskAssigned => 'Task assigned to me',
            self::TrialEnding => 'Billing: free trial ending (owner)',
            self::PaymentFailed => 'Billing: payment failed (owner)',
            self::PaymentRecovered => 'Billing: payment recovered (owner)',
            self::TrialEnded => 'Billing: free trial ended (owner)',
            self::SubscriptionCancelling => 'Billing: cancellation scheduled (owner)',
            self::SubscriptionEnded => 'Billing: subscription ended (owner)',
            self::SubscriptionRenewed => 'Billing: renewal (owner)',
            self::BillingRestricted => 'Billing: paid features paused (owner)',
        };
    }

    /**
     * Built-in default when neither the organization nor the person chose.
     * Replies already have their own dashboard section, so they don't notify in-app by default.
     */
    public function defaultFor(NotificationChannel $channel): bool
    {
        return $channel === NotificationChannel::InApp && $this !== self::CustomerReply;
    }
}

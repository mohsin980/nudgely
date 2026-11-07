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

    case EmailAccountNeedsReconnect = 'email_account_needs_reconnect';

    case AssistantHandover = 'assistant_handover';

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
            self::EmailAccountNeedsReconnect => 'Email account needs reconnecting',
            self::AssistantHandover => 'AI assistant needs you',
        };
    }

    /**
     * One line under the label in notification settings.
     */
    public function description(): string
    {
        return match ($this) {
            self::CustomerReply => 'Get notified when a customer replies to your message.',
            self::EstimateAccepted => 'Know when your estimate is accepted.',
            self::EstimateDeclined => 'Get notified when an estimate is declined.',
            self::AutomationFailed => 'Be alerted if an automation fails.',
            self::FollowUpDue => 'Get reminded about upcoming follow-ups.',
            self::TaskAssigned => 'Know when a task is assigned to you.',
            self::TrialEnding => 'Know when your trial is about to end.',
            self::PaymentFailed => 'Get notified about failed payments.',
            self::PaymentRecovered => 'Be informed when a failed payment is recovered.',
            self::TrialEnded => 'Know when your trial has ended.',
            self::SubscriptionCancelling => 'Get notified when a cancellation is scheduled.',
            self::SubscriptionEnded => 'Be informed when your subscription ends.',
            self::SubscriptionRenewed => 'Know when your subscription renews.',
            self::BillingRestricted => 'Know when paid features are paused.',
            self::EmailAccountNeedsReconnect => 'Know when your business email needs to be connected again.',
            self::AssistantHandover => 'Know when the AI assistant hands a WhatsApp chat over to you.',
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

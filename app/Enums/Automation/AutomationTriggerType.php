<?php

namespace App\Enums\Automation;

/**
 * What can start an automation. Values are stored in the database: never rename them.
 */
enum AutomationTriggerType: string
{
    case CustomerReplyReceived = 'customer_reply_received';
    case CustomerReplyClassified = 'customer_reply_classified';
    case EstimateSent = 'estimate_sent';
    case EstimateViewed = 'estimate_viewed';
    case EstimateExpired = 'estimate_expired';
    case EstimateAccepted = 'estimate_accepted';
    case EstimateDeclined = 'estimate_declined';
    case FollowUpDue = 'follow_up_due';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReplyReceived => 'Customer reply received',
            self::CustomerReplyClassified => 'Customer reply classified',
            self::EstimateSent => 'Estimate sent',
            self::EstimateViewed => 'Estimate viewed',
            self::EstimateExpired => 'Estimate expired',
            self::EstimateAccepted => 'Estimate accepted',
            self::EstimateDeclined => 'Estimate declined',
            self::FollowUpDue => 'Follow-up due',
        };
    }

    /**
     * Whether the application currently dispatches this trigger's event.
     * "Follow-up due" is not dispatched yet.
     */
    public function isAvailable(): bool
    {
        return $this !== self::FollowUpDue;
    }

    public function isEstimateTrigger(): bool
    {
        return in_array($this, [self::EstimateSent, self::EstimateViewed, self::EstimateExpired, self::EstimateAccepted, self::EstimateDeclined], true);
    }
}

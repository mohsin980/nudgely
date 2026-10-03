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
    case FollowUpDue = 'follow_up_due';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReplyReceived => 'Customer reply received',
            self::CustomerReplyClassified => 'Customer reply classified',
            self::EstimateSent => 'Estimate sent',
            self::EstimateViewed => 'Estimate viewed',
            self::EstimateExpired => 'Estimate expired',
            self::FollowUpDue => 'Follow-up due',
        };
    }

    /**
     * Whether the application currently dispatches this trigger's event.
     * Estimates and scheduled follow-ups do not exist yet.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::CustomerReplyReceived, self::CustomerReplyClassified => true,
            default => false,
        };
    }
}

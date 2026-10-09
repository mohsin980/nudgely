<?php

namespace App\Enums\Automation;

use App\Services\Automation\Registry\TriggerRegistry;

/**
 * What can start an automation. Values are stored in the database: never rename them.
 */
enum AutomationTriggerType: string
{
    case CustomerCreated = 'customer_created';
    case CustomerReplyReceived = 'customer_reply_received';
    case CustomerReplyClassified = 'customer_reply_classified';
    case EstimateCreated = 'estimate_created';
    case EstimateSent = 'estimate_sent';
    case EstimateViewed = 'estimate_viewed';
    case EstimateExpired = 'estimate_expired';
    case EstimateAccepted = 'estimate_accepted';
    case EstimateDeclined = 'estimate_declined';
    case FollowUpDue = 'follow_up_due';
    case FollowUpCompleted = 'follow_up_completed';
    case ConversationClosed = 'conversation_closed';
    case ConversationReopened = 'conversation_reopened';

    /**
     * Label, description and what each trigger provides live in TriggerRegistry.
     */
    public function label(): string
    {
        return TriggerRegistry::get($this)->label;
    }

    /**
     * Whether the application dispatches this trigger's event (only those can be chosen).
     */
    public function isAvailable(): bool
    {
        return TriggerRegistry::get($this)->available;
    }

    public function isEstimateTrigger(): bool
    {
        return in_array($this, [self::EstimateCreated, self::EstimateSent, self::EstimateViewed, self::EstimateExpired, self::EstimateAccepted, self::EstimateDeclined], true);
    }
}

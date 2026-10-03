<?php

namespace App\Enums;

/**
 * Why the system decided a follow-up should not run. Stored as follow_ups.skip_reason.
 */
enum FollowUpSkipReason: string
{
    case CustomerReplied = 'customer_replied';
    case OptedOut = 'opted_out';
    case ConversationClosed = 'conversation_closed';
    case ConversationMissing = 'conversation_missing';
    case CustomerUnavailable = 'customer_unavailable';
    case AutomationInactive = 'automation_inactive';
    case AutomationDeleted = 'automation_deleted';
    case AutomationsDisabled = 'automations_disabled';
    case AnotherFollowUpCompleted = 'another_follow_up_completed';
    case RecentlyFollowedUp = 'recently_followed_up';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReplied => 'Customer replied before the follow-up date.',
            self::OptedOut => 'Customer opted out of email.',
            self::ConversationClosed => 'The conversation was closed.',
            self::ConversationMissing => 'The conversation no longer exists.',
            self::CustomerUnavailable => 'The customer has no usable email address.',
            self::AutomationInactive => 'The automation that scheduled it is no longer active.',
            self::AutomationDeleted => 'The automation that scheduled it was deleted.',
            self::AutomationsDisabled => 'Automations are turned off.',
            self::AnotherFollowUpCompleted => 'Another follow-up with this customer was already completed.',
            self::RecentlyFollowedUp => 'The customer already received an automated follow-up recently.',
        };
    }
}

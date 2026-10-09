<?php

namespace App\Enums\Automation;

use App\Services\Automation\Registry\ActionRegistry;

/**
 * What an automation can do. Values are stored in the database: never rename them.
 * Labels, fields and requirements live in ActionRegistry.
 */
enum AutomationActionType: string
{
    case CreateTask = 'create_task';
    case ScheduleFollowUp = 'schedule_follow_up';
    case SendEmail = 'send_email';
    case AddCustomerTag = 'add_customer_tag';
    case RemoveCustomerTag = 'remove_customer_tag';
    case UpdateConversationStatus = 'update_conversation_status';
    case NotifyUser = 'notify_user';
    case CompleteFollowUp = 'complete_follow_up';
    case CancelFollowUp = 'cancel_follow_up';

    public function label(): string
    {
        return ActionRegistry::get($this)->label;
    }

    /**
     * Actions that contact customers need explicit approval unless an organization opts in.
     */
    public function requiresApprovalByDefault(): bool
    {
        return $this === self::SendEmail;
    }
}

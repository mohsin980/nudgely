<?php

namespace App\Enums\Automation;

/**
 * The only things an automation can do. Dangerous actions (prices, discounts,
 * deletions, bookings, refunds, payments, permissions) are deliberately absent.
 */
enum AutomationActionType: string
{
    case CreateTask = 'create_task';
    case ScheduleFollowUp = 'schedule_follow_up';
    case SendEmail = 'send_email';
    case AddCustomerTag = 'add_customer_tag';
    case UpdateConversationStatus = 'update_conversation_status';
    case NotifyUser = 'notify_user';

    public function label(): string
    {
        return match ($this) {
            self::CreateTask => 'Create task',
            self::ScheduleFollowUp => 'Schedule follow-up',
            self::SendEmail => 'Send email',
            self::AddCustomerTag => 'Add customer tag',
            self::UpdateConversationStatus => 'Update conversation status',
            self::NotifyUser => 'Notify user',
        };
    }

    /**
     * Actions that contact customers need explicit approval unless an organization opts in.
     */
    public function requiresApprovalByDefault(): bool
    {
        return $this === self::SendEmail;
    }
}

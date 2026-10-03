<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\ConversationStatus;
use App\Models\AutomationAction;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;

/**
 * update_conversation_status: sets the event's conversation to open, waiting_customer,
 * waiting_business or closed. Setting the current status again is a no-op.
 */
class UpdateConversationStatusAction implements AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $status = ConversationStatus::tryFrom((string) ($action->configuration['status'] ?? ''));

        if ($status === null) {
            return AutomationActionResult::failed('Invalid conversation status.');
        }

        $conversation = $context->conversation();

        if ($conversation === null) {
            return AutomationActionResult::failed($context->conversationId === null
                ? 'This event has no conversation.'
                : 'The conversation does not belong to this organization.');
        }

        if ($conversation->status === $status) {
            return AutomationActionResult::skipped("Conversation is already {$status->label()}.", ['reason' => 'unchanged', 'status' => $status->value]);
        }

        $previous = $conversation->status;
        $conversation->forceFill(['status' => $status])->save();

        return AutomationActionResult::completed("Conversation marked {$status->label()}.", [
            'conversation_id' => $conversation->id,
            'from' => $previous?->value,
            'status' => $status->value,
        ]);
    }
}

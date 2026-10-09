<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reply from the conversation's customer was stored and attached to the conversation.
 * Replies held for review (unexpected sender) do not raise this event.
 */
final class CustomerReplyReceived implements AutomationEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly int $customerId,
    ) {}

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function triggerType(): AutomationTriggerType
    {
        return AutomationTriggerType::CustomerReplyReceived;
    }

    public function eventId(): string
    {
        return 'message:'.$this->messageId;
    }
}

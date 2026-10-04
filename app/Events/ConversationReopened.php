<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A closed conversation was reopened, by a person or by a customer reply.
 */
final class ConversationReopened implements AutomationEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly int $customerId,
        public readonly int $conversationEventId,
    ) {}

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function triggerType(): AutomationTriggerType
    {
        return AutomationTriggerType::ConversationReopened;
    }

    public function eventId(): string
    {
        return 'conversation_event:'.$this->conversationEventId;
    }
}

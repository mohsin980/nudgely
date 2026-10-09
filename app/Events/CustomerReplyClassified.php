<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Models\MessageClassification;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer reply received a new successful AI classification (automatic or reclassification).
 *
 * Carries IDs plus intent and confidence for condition checks; the classification row
 * (`message_classifications`) remains the source of truth.
 */
final class CustomerReplyClassified implements AutomationEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly int $customerId,
        public readonly int $classificationId,
        public readonly CustomerReplyIntent $intent,
        public readonly float $confidence,
    ) {}

    public static function fromClassification(MessageClassification $classification, int $customerId): self
    {
        return new self(
            organizationId: $classification->organization_id,
            messageId: $classification->message_id,
            conversationId: $classification->conversation_id,
            customerId: $customerId,
            classificationId: $classification->id,
            intent: $classification->intent,
            confidence: $classification->confidence,
        );
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function triggerType(): AutomationTriggerType
    {
        return AutomationTriggerType::CustomerReplyClassified;
    }

    public function eventId(): string
    {
        return 'classification:'.$this->classificationId;
    }
}

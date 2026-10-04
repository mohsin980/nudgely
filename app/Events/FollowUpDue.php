<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A follow-up reached its due time (dispatched by the scheduler, FollowUpProcessor::markDue()).
 *
 * The event ID includes the due time, so a rescheduled follow-up that comes due again runs again.
 */
final class FollowUpDue implements AutomationEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $followUpId,
        public readonly ?int $conversationId = null,
        public readonly ?int $customerId = null,
        public readonly ?int $estimateId = null,
        public readonly int $dueTimestamp = 0,
    ) {}

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function triggerType(): AutomationTriggerType
    {
        return AutomationTriggerType::FollowUpDue;
    }

    public function eventId(): string
    {
        return 'follow_up:'.$this->followUpId.($this->dueTimestamp ? ':'.$this->dueTimestamp : '');
    }
}

<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A scheduled follow-up reached its due time.
 *
 * Not dispatched yet: scheduled follow-ups arrive with the schedule_follow_up action and
 * the scheduler in a later task. Defined now so the trigger has a stable contract.
 */
final class FollowUpDue implements AutomationEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $followUpId,
        public readonly ?int $conversationId = null,
        public readonly ?int $customerId = null,
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
        return 'follow_up:'.$this->followUpId;
    }
}

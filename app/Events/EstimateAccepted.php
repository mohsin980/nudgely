<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer accepted an estimate. No payment or invoice is involved.
 *
 * Dispatched by EstimateService after the change is committed, once per estimate.
 */
final class EstimateAccepted implements AutomationEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $estimateId,
        public readonly ?int $customerId = null,
        public readonly ?int $conversationId = null,
    ) {}

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function triggerType(): AutomationTriggerType
    {
        return AutomationTriggerType::EstimateAccepted;
    }

    public function eventId(): string
    {
        return 'estimate:'.$this->estimateId;
    }
}

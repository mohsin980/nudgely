<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An estimate email was delivered to the customer (not just queued).
 *
 * Dispatched by EstimateService after the change is committed, once per estimate.
 */
final class EstimateSent implements AutomationEvent, ShouldDispatchAfterCommit
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
        return AutomationTriggerType::EstimateSent;
    }

    public function eventId(): string
    {
        return 'estimate:'.$this->estimateId;
    }
}

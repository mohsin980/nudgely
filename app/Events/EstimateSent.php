<?php

namespace App\Events;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An estimate was sent to a customer.
 *
 * Not dispatched yet: QuoteFollow has no estimates feature. Defined so the automation
 * trigger has a stable contract when estimates are built.
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

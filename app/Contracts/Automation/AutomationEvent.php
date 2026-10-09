<?php

namespace App\Contracts\Automation;

use App\Enums\Automation\AutomationTriggerType;

/**
 * An application event automations can react to.
 *
 * Events only announce what happened. They carry IDs and small facts, never secrets
 * or message content, and never execute automations themselves.
 */
interface AutomationEvent
{
    public function organizationId(): int;

    public function triggerType(): AutomationTriggerType;

    /**
     * Stable identifier of this occurrence; with organization, automation and trigger
     * type it forms the automation run idempotency boundary.
     */
    public function eventId(): string;
}

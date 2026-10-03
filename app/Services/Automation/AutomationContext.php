<?php

namespace App\Services\Automation;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Events\CustomerReplyClassified;
use App\Events\CustomerReplyReceived;
use App\Events\EstimateExpired;
use App\Events\EstimateSent;
use App\Events\EstimateViewed;
use App\Events\FollowUpDue;
use App\Models\AutomationAction;
use App\Models\Conversation;
use App\Models\Customer;

/**
 * What an automation knows about the event it is reacting to.
 *
 * Related records are always loaded within the context's organization, so an ID that
 * belongs to another tenant simply resolves to null.
 */
final class AutomationContext
{
    private ?Customer $customer = null;

    private ?Conversation $conversation = null;

    private bool $customerLoaded = false;

    private bool $conversationLoaded = false;

    public function __construct(
        public readonly int $organizationId,
        public readonly AutomationTriggerType $triggerType,
        public readonly ?string $eventId = null,
        public readonly ?int $customerId = null,
        public readonly ?int $conversationId = null,
        public readonly ?int $messageId = null,
        public readonly ?int $classificationId = null,
        public readonly ?CustomerReplyIntent $intent = null,
        public readonly ?float $confidence = null,
    ) {}

    public static function fromEvent(AutomationEvent $event): self
    {
        $base = [$event->organizationId(), $event->triggerType(), $event->eventId()];

        return match (true) {
            $event instanceof CustomerReplyClassified => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId, messageId: $event->messageId,
                classificationId: $event->classificationId, intent: $event->intent, confidence: $event->confidence),
            $event instanceof CustomerReplyReceived => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId, messageId: $event->messageId),
            $event instanceof EstimateSent, $event instanceof EstimateViewed, $event instanceof EstimateExpired, $event instanceof FollowUpDue => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId),
            default => new self(...$base),
        };
    }

    public function customer(): ?Customer
    {
        if (! $this->customerLoaded) {
            $this->customerLoaded = true;
            $this->customer = $this->customerId === null ? null : Customer::query()
                ->where('organization_id', $this->organizationId)
                ->find($this->customerId);
        }

        return $this->customer;
    }

    public function conversation(): ?Conversation
    {
        if (! $this->conversationLoaded) {
            $this->conversationLoaded = true;
            $this->conversation = $this->conversationId === null ? null : Conversation::query()
                ->where('organization_id', $this->organizationId)
                ->find($this->conversationId);
        }

        return $this->conversation;
    }

    /**
     * Stable key for one action reacting to one event; null when the event has no ID.
     */
    public function idempotencyKey(AutomationAction $action): ?string
    {
        if ($this->eventId === null) {
            return null;
        }

        return hash('sha256', implode('|', [$this->organizationId, $action->id, $this->triggerType->value, $this->eventId]));
    }

    /**
     * Fill the few supported placeholders. Plain text substitution only: nothing is evaluated.
     */
    public function render(string $template): string
    {
        return strtr($template, [
            '{customer_name}' => $this->customer()?->name ?? 'Customer',
            '{customer_first_name}' => strtok((string) ($this->customer()?->name ?? 'Customer'), ' ') ?: 'Customer',
            '{intent}' => $this->intent?->label() ?? '',
            '{conversation_subject}' => $this->conversation()?->subject ?? '',
        ]);
    }
}

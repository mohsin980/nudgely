<?php

namespace App\Services\Automation;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Events\ConversationClosed;
use App\Events\ConversationReopened;
use App\Events\CustomerCreated;
use App\Events\CustomerReplyClassified;
use App\Events\CustomerReplyReceived;
use App\Events\EstimateAccepted;
use App\Events\EstimateCreated;
use App\Events\EstimateDeclined;
use App\Events\EstimateExpired;
use App\Events\EstimateSent;
use App\Events\EstimateViewed;
use App\Events\FollowUpCompleted;
use App\Events\FollowUpDue;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\AutomationAction;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Organization;
use Carbon\CarbonImmutable;

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

    private ?Estimate $estimate = null;

    private ?FollowUp $followUp = null;

    private ?Organization $organization = null;

    /** @var array<string, bool> */
    private array $loaded = [];

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
        public readonly int $depth = 0,
        public readonly ?int $estimateId = null,
        public readonly ?int $followUpId = null,
        public readonly ?CarbonImmutable $occurredAt = null,
    ) {}

    /**
     * @param  int  $depth  How many automations deep the event was raised (0 = by the app itself).
     */
    public static function fromEvent(AutomationEvent $event, int $depth = 0): self
    {
        $base = [$event->organizationId(), $event->triggerType(), $event->eventId(), 'depth' => $depth, 'occurredAt' => CarbonImmutable::now()];

        return match (true) {
            $event instanceof CustomerReplyClassified => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId, messageId: $event->messageId,
                classificationId: $event->classificationId, intent: $event->intent, confidence: $event->confidence),
            $event instanceof CustomerReplyReceived => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId, messageId: $event->messageId),
            $event instanceof EstimateCreated, $event instanceof EstimateSent, $event instanceof EstimateViewed, $event instanceof EstimateExpired,
            $event instanceof EstimateAccepted, $event instanceof EstimateDeclined => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId, estimateId: $event->estimateId),
            $event instanceof FollowUpDue, $event instanceof FollowUpCompleted => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId, estimateId: $event->estimateId, followUpId: $event->followUpId),
            $event instanceof ConversationClosed, $event instanceof ConversationReopened => new self(...$base,
                customerId: $event->customerId, conversationId: $event->conversationId),
            $event instanceof CustomerCreated => new self(...$base, customerId: $event->customerId),
            default => new self(...$base),
        };
    }

    /**
     * Rebuild a context stored on an automation run.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $int = fn (string $key) => isset($data[$key]) ? (int) $data[$key] : null;

        return new self(
            organizationId: (int) $data['organization_id'],
            triggerType: AutomationTriggerType::from($data['trigger_type']),
            eventId: isset($data['event_id']) ? (string) $data['event_id'] : null,
            customerId: $int('customer_id'),
            conversationId: $int('conversation_id'),
            messageId: $int('message_id'),
            classificationId: $int('classification_id'),
            intent: isset($data['intent']) ? CustomerReplyIntent::tryFrom((string) $data['intent']) : null,
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            depth: (int) ($data['depth'] ?? 0),
            estimateId: $int('estimate_id'),
            followUpId: $int('follow_up_id'),
            occurredAt: isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : null,
        );
    }

    /**
     * IDs and small facts only; safe to store on the run.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'trigger_type' => $this->triggerType->value,
            'event_id' => $this->eventId,
            'customer_id' => $this->customerId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'classification_id' => $this->classificationId,
            'intent' => $this->intent?->value,
            'confidence' => $this->confidence,
            'depth' => $this->depth,
            'estimate_id' => $this->estimateId,
            'follow_up_id' => $this->followUpId,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
        ];
    }

    public function organization(): ?Organization
    {
        return $this->once('organization', fn () => $this->organization = Organization::find($this->organizationId), fn () => $this->organization);
    }

    public function customer(): ?Customer
    {
        return $this->once('customer', fn () => $this->customer = $this->customerId === null ? null
            : Customer::query()->where('organization_id', $this->organizationId)->find($this->customerId), fn () => $this->customer);
    }

    public function conversation(): ?Conversation
    {
        return $this->once('conversation', fn () => $this->conversation = $this->conversationId === null ? null
            : Conversation::query()->where('organization_id', $this->organizationId)->find($this->conversationId), fn () => $this->conversation);
    }

    public function followUp(): ?FollowUp
    {
        return $this->once('followUp', fn () => $this->followUp = $this->followUpId === null ? null
            : FollowUp::query()->where('organization_id', $this->organizationId)->find($this->followUpId), fn () => $this->followUp);
    }

    /**
     * The estimate the event is about; for other events, the conversation's most recent sent
     * estimate (so "{{estimate.number}}" works in a follow-up to a customer reply).
     */
    public function estimate(): ?Estimate
    {
        return $this->once('estimate', function () {
            $query = Estimate::query()->where('organization_id', $this->organizationId);

            $this->estimate = match (true) {
                $this->estimateId !== null => $query->find($this->estimateId),
                $this->conversationId !== null => $query->where('conversation_id', $this->conversationId)
                    ->whereNotIn('status', ['draft', 'cancelled'])->latest('sent_at')->latest('id')->first(),
                default => null,
            };
        }, fn () => $this->estimate);
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
     * Fill a task/notification template: {{variables}} (VariableRegistry) and the older
     * single-brace placeholders. Plain text substitution only: nothing is evaluated.
     *
     * @throws InvalidEmailTemplateException when it uses data this event doesn't have
     */
    public function render(string $template): string
    {
        $text = strtr($template, [
            '{customer_name}' => $this->customer()?->name ?? 'Customer',
            '{customer_first_name}' => strtok((string) ($this->customer()?->name ?? 'Customer'), ' ') ?: 'Customer',
            '{intent}' => $this->intent?->label() ?? '',
            '{conversation_subject}' => $this->conversation()?->subject ?? '',
        ]);

        return str_contains($text, '{{') ? $this->renderTemplate($text) : $text;
    }

    /**
     * Fill {{variables}} from this event's records.
     *
     * @throws InvalidEmailTemplateException
     */
    public function renderTemplate(string $template): string
    {
        return app(EmailTemplateRenderer::class)->render($template, $this->customer(), $this->organization(), $this->estimate(), $this->followUp(), $this->conversation());
    }

    /**
     * @template T
     *
     * @param  callable(): void  $load
     * @param  callable(): T  $get
     * @return T
     */
    private function once(string $key, callable $load, callable $get): mixed
    {
        if (! isset($this->loaded[$key])) {
            $this->loaded[$key] = true;
            $load();
        }

        return $get();
    }
}

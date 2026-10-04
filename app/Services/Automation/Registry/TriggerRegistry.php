<?php

namespace App\Services\Automation\Registry;

use App\Enums\Automation\AutomationTriggerType as T;

/**
 * Every automation trigger, in one place: the builder's choices, validation, the engine's
 * context and the template variables all come from here.
 */
final class TriggerRegistry
{
    /** @var array<string, TriggerDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, TriggerDefinition>
     */
    public static function all(): array
    {
        $c = Subject::Customer;
        $conv = Subject::Conversation;

        return self::$definitions ??= collect([
            new TriggerDefinition(T::CustomerCreated, 'Customer created', 'Runs when a customer is added.', [$c]),
            new TriggerDefinition(T::CustomerReplyReceived, 'Customer reply received', 'Runs as soon as a customer replies by email, before the AI reads it.', [$c, $conv, Subject::Message]),
            new TriggerDefinition(T::CustomerReplyClassified, 'Customer reply received (with AI intent)', 'Runs when a customer reply has been read by the AI, so you can check its intent (e.g. Ready to book).', [$c, $conv, Subject::Message, Subject::Classification]),
            new TriggerDefinition(T::EstimateCreated, 'Estimate created', 'Runs when a draft estimate is created.', [$c, Subject::Estimate]),
            new TriggerDefinition(T::EstimateSent, 'Estimate sent', 'Runs when an estimate is delivered to the customer.', [$c, $conv, Subject::Estimate]),
            new TriggerDefinition(T::EstimateViewed, 'Estimate viewed', 'Runs the first time the customer opens their estimate.', [$c, $conv, Subject::Estimate]),
            new TriggerDefinition(T::EstimateAccepted, 'Estimate accepted', 'Runs when the customer accepts an estimate.', [$c, $conv, Subject::Estimate]),
            new TriggerDefinition(T::EstimateDeclined, 'Estimate declined', 'Runs when the customer declines an estimate.', [$c, $conv, Subject::Estimate]),
            new TriggerDefinition(T::EstimateExpired, 'Estimate expired', 'Runs when an estimate passes its "valid until" date without a decision.', [$c, $conv, Subject::Estimate]),
            new TriggerDefinition(T::FollowUpDue, 'Follow-up due', 'Runs when a follow-up reaches its due time.', [$c, Subject::FollowUp]),
            new TriggerDefinition(T::FollowUpCompleted, 'Follow-up completed', 'Runs when a follow-up is completed (by a person or when its email is sent).', [$c, Subject::FollowUp]),
            new TriggerDefinition(T::ConversationClosed, 'Conversation closed', 'Runs when someone closes a conversation.', [$c, $conv]),
            new TriggerDefinition(T::ConversationReopened, 'Conversation reopened', 'Runs when a closed conversation is reopened (by a person or a customer reply).', [$c, $conv]),
        ])->keyBy(fn (TriggerDefinition $d) => $d->key())->all();
    }

    public static function get(T|string $type): TriggerDefinition
    {
        $key = $type instanceof T ? $type->value : $type;

        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown trigger [{$key}].");
    }

    public static function find(?string $key): ?TriggerDefinition
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /**
     * Triggers the application dispatches: the only ones a business can choose.
     *
     * @return list<TriggerDefinition>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::all(), fn (TriggerDefinition $d) => $d->available));
    }
}

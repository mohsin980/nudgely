<?php

namespace App\Services\Automation\Registry;

use App\Enums\AttentionPriority;
use App\Enums\Automation\AutomationConditionType as C;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\CustomerStatus;
use App\Enums\EstimateStatus;
use App\Enums\FollowUpStatus;
use App\Services\Automation\Registry\ConditionFieldDefinition as F;

/**
 * Every field a condition can test. Only fields whose records the trigger provides are offered;
 * ConditionEvaluator reads the actual values.
 */
final class ConditionFieldRegistry
{
    /** @var array<string, F>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, F>
     */
    public static function all(): array
    {
        $options = fn (string $enum) => collect($enum::cases())->mapWithKeys(fn ($case) => [$case->value => method_exists($case, 'label') ? $case->label() : ucfirst($case->value)])->all();
        $customer = [Subject::Customer];
        $conversation = [Subject::Conversation];
        $estimate = [Subject::Estimate];
        $followUp = [Subject::FollowUp];

        return self::$definitions ??= collect([
            // Customer reply (AI)
            new F(C::IntentEquals, 'AI intent of the reply', 'Customer reply', F::ENUM, [Subject::Classification], $options(CustomerReplyIntent::class)),
            new F(C::ConfidenceGreaterThan, 'AI confidence', 'Customer reply', F::PERCENT, [Subject::Classification], help: 'As a percentage, e.g. 80.'),
            // Customer
            new F(C::CustomerReplied, 'Customer has replied (since this automation started)', 'Customer', F::BOOLEAN, $conversation,
                help: 'Checked when the automation runs, after any wait.', failureReason: 'Customer already replied.'),
            new F(C::CustomerStatusEquals, 'Customer status', 'Customer', F::ENUM, $customer, $options(CustomerStatus::class)),
            new F(C::CustomerHasEmail, 'Customer has an email address', 'Customer', F::BOOLEAN, $customer),
            new F(C::CustomerHasPhone, 'Customer has a phone number', 'Customer', F::BOOLEAN, $customer),
            new F(C::CustomerEmail, 'Customer email', 'Customer', F::STRING, $customer),
            new F(C::CustomerCompany, 'Customer company', 'Customer', F::STRING, $customer),
            // Conversation
            new F(C::ConversationStatusEquals, 'Conversation status', 'Conversation', F::ENUM, $conversation, $options(ConversationStatus::class)),
            new F(C::ConversationPriority, 'Conversation priority', 'Conversation', F::ENUM, $conversation, collect(AttentionPriority::cases())->mapWithKeys(fn ($p) => [$p->value => ucfirst($p->value)])->all()),
            new F(C::ConversationIntent, 'Latest AI intent in the conversation', 'Conversation', F::ENUM, $conversation, $options(CustomerReplyIntent::class)),
            new F(C::ConversationSubject, 'Conversation subject', 'Conversation', F::STRING, $conversation),
            new F(C::DaysSinceLastMessage, 'Days since the last message', 'Conversation', F::NUMBER, $conversation),
            // Estimate
            new F(C::EstimateStatusEquals, 'Estimate status', 'Estimate', F::ENUM, $estimate, $options(EstimateStatus::class)),
            new F(C::EstimateTotal, 'Estimate total', 'Estimate', F::MONEY, $estimate, help: 'In dollars, e.g. 1000.'),
            new F(C::EstimateTitle, 'Estimate title', 'Estimate', F::STRING, $estimate),
            new F(C::EstimateValidUntil, 'Estimate valid until', 'Estimate', F::DATE, $estimate),
            // Follow-up
            new F(C::FollowUpStatus, 'Follow-up status', 'Follow-up', F::ENUM, $followUp, $options(FollowUpStatus::class)),
            new F(C::FollowUpDueAt, 'Follow-up due date', 'Follow-up', F::DATE, $followUp),
        ])->keyBy(fn (F $f) => $f->key())->all();
    }

    /**
     * Fields the builder offers (all of them today; kept separate so a field can be retired
     * without breaking stored conditions).
     *
     * @return list<F>
     */
    public static function selectable(): array
    {
        return array_values(self::all());
    }

    public static function get(C|string $type): F
    {
        $key = $type instanceof C ? $type->value : $type;

        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown condition field [{$key}].");
    }
}

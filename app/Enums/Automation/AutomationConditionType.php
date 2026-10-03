<?php

namespace App\Enums\Automation;

/**
 * The facts an automation condition can test. Conditions are data, never code:
 * each type is compared with a controlled operator against a stored value.
 */
enum AutomationConditionType: string
{
    case IntentEquals = 'intent_equals';
    case ConfidenceGreaterThan = 'confidence_greater_than';
    case CustomerStatusEquals = 'customer_status_equals';
    case EstimateStatusEquals = 'estimate_status_equals';
    case DaysSinceLastMessage = 'days_since_last_message';
    case ConversationStatusEquals = 'conversation_status_equals';

    public function label(): string
    {
        return match ($this) {
            self::IntentEquals => 'Intent',
            self::ConfidenceGreaterThan => 'Confidence',
            self::CustomerStatusEquals => 'Customer status',
            self::EstimateStatusEquals => 'Estimate status',
            self::DaysSinceLastMessage => 'Days since last message',
            self::ConversationStatusEquals => 'Conversation status',
        };
    }

    /**
     * Operators that make sense for this condition type.
     *
     * @return list<AutomationConditionOperator>
     */
    public function allowedOperators(): array
    {
        return match ($this) {
            self::IntentEquals, self::CustomerStatusEquals, self::EstimateStatusEquals, self::ConversationStatusEquals => [
                AutomationConditionOperator::Equals,
                AutomationConditionOperator::NotEquals,
            ],
            self::ConfidenceGreaterThan, self::DaysSinceLastMessage => [
                AutomationConditionOperator::GreaterThan,
                AutomationConditionOperator::GreaterThanOrEqual,
                AutomationConditionOperator::LessThan,
                AutomationConditionOperator::LessThanOrEqual,
            ],
        };
    }

    public function defaultOperator(): AutomationConditionOperator
    {
        return $this->allowedOperators()[0];
    }
}

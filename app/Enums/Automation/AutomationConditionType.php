<?php

namespace App\Enums\Automation;

use App\Services\Automation\Registry\ConditionFieldRegistry;

/**
 * The facts an automation condition can test. Conditions are data, never code:
 * each type is compared with a controlled operator against a stored value.
 */
enum AutomationConditionType: string
{
    // Task 7 keys (stored values: never rename).
    case IntentEquals = 'intent_equals';
    case ConfidenceGreaterThan = 'confidence_greater_than';
    case CustomerStatusEquals = 'customer_status_equals';
    case EstimateStatusEquals = 'estimate_status_equals';
    case DaysSinceLastMessage = 'days_since_last_message';
    case ConversationStatusEquals = 'conversation_status_equals';

    // Task 12 fields.
    case CustomerReplied = 'customer_replied';
    case CustomerHasEmail = 'customer_has_email';
    case CustomerHasPhone = 'customer_has_phone';
    case CustomerEmail = 'customer_email';
    case CustomerCompany = 'customer_company';
    case ConversationPriority = 'conversation_priority';
    case ConversationIntent = 'conversation_intent';
    case ConversationSubject = 'conversation_subject';
    case EstimateTotal = 'estimate_total';
    case EstimateTitle = 'estimate_title';
    case EstimateValidUntil = 'estimate_valid_until';
    case FollowUpStatus = 'follow_up_status';
    case FollowUpDueAt = 'follow_up_due_at';

    /**
     * Label, data type and operators live in ConditionFieldRegistry.
     */
    public function label(): string
    {
        return ConditionFieldRegistry::get($this)->label;
    }

    /**
     * @return list<AutomationConditionOperator>
     */
    public function allowedOperators(): array
    {
        return ConditionFieldRegistry::get($this)->operators();
    }

    public function defaultOperator(): AutomationConditionOperator
    {
        return $this->allowedOperators()[0];
    }
}

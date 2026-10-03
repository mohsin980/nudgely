<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Models\Automation;
use App\Models\AutomationCondition;

/**
 * Decides whether an automation's conditions match an event.
 *
 * Conditions are data: a known type, an allowed operator and a value that is parsed
 * strictly for that type, then compared with plain PHP comparisons. Nothing is ever
 * evaluated or executed. All conditions are combined with AND; no conditions means a match.
 */
class ConditionEvaluator
{
    /**
     * @param  iterable<AutomationCondition>|null  $conditions  Defaults to the automation's conditions.
     *
     * @throws InvalidAutomationConditionException
     */
    public function matches(Automation $automation, AutomationContext $context, ?iterable $conditions = null): bool
    {
        foreach ($conditions ?? $automation->conditions as $condition) {
            if (! $this->evaluate($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws InvalidAutomationConditionException
     */
    public function evaluate(AutomationCondition $condition, AutomationContext $context): bool
    {
        [$type, $operator] = $this->resolve($condition);

        $expected = $this->parseValue($type, $condition->getAttributes()['value'] ?? null);
        $actual = $this->actualValue($type, $context);

        // Missing data never matches (e.g. no confidence on a "reply received" event).
        if ($actual === null) {
            return false;
        }

        return match ($operator) {
            AutomationConditionOperator::Equals => $actual === $expected,
            AutomationConditionOperator::NotEquals => $actual !== $expected,
            AutomationConditionOperator::GreaterThan => $actual > $expected,
            AutomationConditionOperator::GreaterThanOrEqual => $actual >= $expected,
            AutomationConditionOperator::LessThan => $actual < $expected,
            AutomationConditionOperator::LessThanOrEqual => $actual <= $expected,
        };
    }

    /**
     * Check a condition without evaluating it (for builders and before saving).
     *
     * @throws InvalidAutomationConditionException
     */
    public function assertValid(string|AutomationConditionType $type, string|AutomationConditionOperator $operator, mixed $value): void
    {
        $condition = new AutomationCondition;
        $condition->setRawAttributes([
            'type' => $type instanceof AutomationConditionType ? $type->value : $type,
            'operator' => $operator instanceof AutomationConditionOperator ? $operator->value : $operator,
            'value' => $value,
        ]);

        [$resolvedType] = $this->resolve($condition);
        $this->parseValue($resolvedType, $value);
    }

    /**
     * @return array{0: AutomationConditionType, 1: AutomationConditionOperator}
     */
    private function resolve(AutomationCondition $condition): array
    {
        // Raw values, so a corrupted stored value is rejected instead of crashing the enum cast.
        $raw = $condition->getAttributes();
        $type = AutomationConditionType::tryFrom((string) ($raw['type'] ?? ''))
            ?? throw new InvalidAutomationConditionException('Unknown condition type.');
        $operator = AutomationConditionOperator::tryFrom((string) ($raw['operator'] ?? ''))
            ?? throw new InvalidAutomationConditionException('Unknown condition operator.');

        if (! in_array($operator, $type->allowedOperators(), true)) {
            throw new InvalidAutomationConditionException("The \"{$operator->label()}\" operator cannot be used with {$type->label()}.");
        }

        if (in_array($type, [AutomationConditionType::CustomerStatusEquals, AutomationConditionType::EstimateStatusEquals], true)) {
            throw new InvalidAutomationConditionException("{$type->label()} conditions are not available yet.");
        }

        return [$type, $operator];
    }

    /**
     * Strictly parse the stored value for its condition type.
     */
    private function parseValue(AutomationConditionType $type, mixed $value): string|float|int
    {
        $value = is_string($value) ? trim($value) : $value;

        return match ($type) {
            AutomationConditionType::IntentEquals => (is_string($value) ? CustomerReplyIntent::tryFrom($value)?->value : null)
                ?? throw new InvalidAutomationConditionException('Unknown intent.'),
            AutomationConditionType::ConversationStatusEquals => (is_string($value) ? ConversationStatus::tryFrom($value)?->value : null)
                ?? throw new InvalidAutomationConditionException('Unknown conversation status.'),
            AutomationConditionType::ConfidenceGreaterThan => $this->confidence($value),
            AutomationConditionType::DaysSinceLastMessage => $this->days($value),
            default => throw new InvalidAutomationConditionException("{$type->label()} conditions are not available yet."),
        };
    }

    private function actualValue(AutomationConditionType $type, AutomationContext $context): string|float|int|null
    {
        return match ($type) {
            AutomationConditionType::IntentEquals => $context->intent?->value,
            AutomationConditionType::ConfidenceGreaterThan => $context->confidence,
            AutomationConditionType::ConversationStatusEquals => $context->conversation()?->status?->value,
            AutomationConditionType::DaysSinceLastMessage => ($last = $context->conversation()?->last_message_at) === null
                ? null
                : (int) floor($last->diffInDays(now(), absolute: true)),
            default => null,
        };
    }

    private function confidence(mixed $value): float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', (string) $value)) {
            throw new InvalidAutomationConditionException('Confidence must be a number from 0 to 1, e.g. 0.8.');
        }

        return (float) $value;
    }

    private function days(mixed $value): int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,4}$/', (string) $value)) {
            throw new InvalidAutomationConditionException('Days must be a whole number from 0 to 9999.');
        }

        return (int) $value;
    }
}

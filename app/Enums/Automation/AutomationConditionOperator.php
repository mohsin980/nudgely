<?php

namespace App\Enums\Automation;

/**
 * Safe comparisons a condition can make. Which ones a field offers depends on its data type
 * (ConditionFieldRegistry); nothing is ever evaluated as code or SQL.
 */
enum AutomationConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';
    case Before = 'before';
    case After = 'after';
    case On = 'on';
    case OnOrBefore = 'on_or_before';
    case OnOrAfter = 'on_or_after';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'equals',
            self::NotEquals => 'does not equal',
            self::GreaterThan => 'greater than',
            self::GreaterThanOrEqual => 'at least',
            self::LessThan => 'less than',
            self::LessThanOrEqual => 'at most',
            self::Contains => 'contains',
            self::NotContains => 'does not contain',
            self::IsTrue => 'is true',
            self::IsFalse => 'is false',
            self::Before => 'is before',
            self::After => 'is after',
            self::On => 'is on',
            self::OnOrBefore => 'is on or before',
            self::OnOrAfter => 'is on or after',
        };
    }

    /**
     * Operators that compare with nothing (the value field is hidden).
     */
    public function needsValue(): bool
    {
        return $this !== self::IsTrue && $this !== self::IsFalse;
    }
}

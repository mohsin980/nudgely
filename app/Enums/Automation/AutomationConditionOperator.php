<?php

namespace App\Enums\Automation;

enum AutomationConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'equals',
            self::NotEquals => 'does not equal',
            self::GreaterThan => 'greater than',
            self::GreaterThanOrEqual => 'at least',
            self::LessThan => 'less than',
            self::LessThanOrEqual => 'at most',
        };
    }
}

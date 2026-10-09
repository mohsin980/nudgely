<?php

namespace App\Services\Automation\Registry;

use App\Enums\Automation\AutomationConditionOperator as Op;
use App\Enums\Automation\AutomationConditionType;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Support\Money;

/**
 * One thing a condition can test: its label, data type, operators, the records it needs,
 * and how a stored value is parsed (strictly) and shown.
 */
final readonly class ConditionFieldDefinition
{
    public const STRING = 'string';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    public const ENUM = 'enum';

    public const BOOLEAN = 'boolean';

    public const DATE = 'date';

    /**
     * @param  list<Subject>  $requires
     * @param  array<string, string>  $options  For enums: value => label.
     * @param  ?string  $failureReason  Shown when the condition stops a run (e.g. "Customer already replied.").
     */
    public function __construct(
        public AutomationConditionType $type,
        public string $label,
        public string $group,
        public string $dataType,
        public array $requires,
        public array $options = [],
        public ?string $help = null,
        public ?string $failureReason = null,
    ) {}

    public function key(): string
    {
        return $this->type->value;
    }

    /**
     * @return list<Op>
     */
    public function operators(): array
    {
        return match ($this->dataType) {
            self::STRING => [Op::Equals, Op::NotEquals, Op::Contains, Op::NotContains],
            self::NUMBER, self::MONEY, self::PERCENT => [Op::GreaterThan, Op::GreaterThanOrEqual, Op::LessThan, Op::LessThanOrEqual, Op::Equals, Op::NotEquals],
            self::ENUM => [Op::Equals, Op::NotEquals],
            self::BOOLEAN => [Op::IsTrue, Op::IsFalse],
            self::DATE => [Op::Before, Op::After, Op::On, Op::OnOrBefore, Op::OnOrAfter],
        };
    }

    /**
     * Parse a stored value strictly for this field: string|int|float, or null for booleans.
     *
     * @throws InvalidAutomationConditionException
     */
    public function parse(mixed $value): string|int|float|null
    {
        $value = is_string($value) ? trim($value) : $value;
        $text = is_scalar($value) ? (string) $value : '';

        return match ($this->dataType) {
            self::BOOLEAN => null,
            self::ENUM => array_key_exists($text, $this->options) ? $text
                : throw new InvalidAutomationConditionException('Unknown '.strtolower($this->label).'.'),
            self::STRING => $text !== '' && mb_strlen($text) <= 255 ? mb_strtolower($text)
                : throw new InvalidAutomationConditionException("Enter text to compare {$this->label} with (up to 255 characters)."),
            self::NUMBER => preg_match('/^\d{1,4}$/', $text) ? (int) $text
                : throw new InvalidAutomationConditionException("{$this->label} must be a whole number from 0 to 9999."),
            self::MONEY => Money::tryParse($text) ?? throw new InvalidAutomationConditionException('Enter an amount like 1000 or 1000.00.'),
            self::PERCENT => preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', $text) ? (float) $text
                : throw new InvalidAutomationConditionException('Confidence must be a number from 0 to 1, e.g. 0.8.'),
            self::DATE => self::validDate($text) ? $text
                : throw new InvalidAutomationConditionException('Enter a date (YYYY-MM-DD), "today", or "today+3" / "today-3".'),
        };
    }

    /**
     * The stored value as people read it ("Ready to book", "$1,000.00", "80%").
     */
    public function describe(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return match ($this->dataType) {
            self::ENUM => $this->options[$text] ?? $text,
            self::MONEY => ($cents = Money::tryParse($text)) === null ? $text : Money::format($cents),
            self::PERCENT => is_numeric($text) ? round((float) $text * 100, 2).'%' : $text,
            self::DATE => self::describeDate($text),
            self::BOOLEAN => '',
            default => $text,
        };
    }

    public static function validDate(string $value): bool
    {
        if (preg_match('/^today([+-]\d{1,3})?$/', $value, $m)) {
            return abs((int) ($m[1] ?? 0)) <= 365;
        }

        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function describeDate(string $value): string
    {
        if (! preg_match('/^today([+-]\d{1,3})?$/', $value, $m)) {
            return $value;
        }

        $days = (int) ($m[1] ?? 0);

        return match (true) {
            $days === 0 => 'today',
            $days > 0 => "today + {$days} ".($days === 1 ? 'day' : 'days'),
            default => 'today − '.abs($days).' '.(abs($days) === 1 ? 'day' : 'days'),
        };
    }
}

<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationConditionOperator as Op;
use App\Enums\Automation\AutomationConditionType as C;
use App\Enums\MessageDirection;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Models\Automation;
use App\Models\AutomationCondition;
use App\Models\Message;
use App\Services\Automation\Registry\ConditionFieldDefinition as F;
use App\Services\Automation\Registry\ConditionFieldRegistry;
use App\Services\Dashboard\AttentionPriorityRules;
use App\Support\Money;

/**
 * Decides whether an automation's conditions match an event.
 *
 * Conditions are data: a known field (ConditionFieldRegistry), an operator allowed for its
 * data type and a value parsed strictly for that type, compared with plain PHP comparisons.
 * Nothing is ever evaluated as code or SQL. "all" needs every condition, "any" at least one;
 * no conditions always match. Missing data (e.g. no estimate) never matches.
 */
class ConditionEvaluator
{
    public const ALL = 'all';

    public const ANY = 'any';

    public function __construct(private readonly AttentionPriorityRules $priorities) {}

    /**
     * @param  iterable<AutomationCondition>|null  $conditions  Defaults to the automation's conditions.
     *
     * @throws InvalidAutomationConditionException
     */
    public function matches(Automation $automation, AutomationContext $context, ?iterable $conditions = null, ?string $match = null): bool
    {
        return $this->explain($automation, $context, $conditions, $match)['matched'];
    }

    /**
     * Evaluate every condition and say what was compared, for logs and test runs.
     *
     * @param  iterable<AutomationCondition>|null  $conditions
     * @return array{matched: bool, match: string, results: list<array{label: string, actual: string, passed: bool}>, reason: ?string}
     *
     * @throws InvalidAutomationConditionException
     */
    public function explain(Automation $automation, AutomationContext $context, ?iterable $conditions = null, ?string $match = null): array
    {
        $match = ($match ?? $automation->condition_match ?? self::ALL) === self::ANY ? self::ANY : self::ALL;
        $results = [];

        foreach ($conditions ?? $automation->conditions as $condition) {
            $results[] = $this->check($condition, $context);
        }

        $passed = array_filter($results, fn (array $r) => $r['passed']);
        $matched = $results === [] || ($match === self::ANY ? $passed !== [] : count($passed) === count($results));
        $firstFailure = collect($results)->first(fn (array $r) => ! $r['passed']);

        return [
            'matched' => $matched,
            'match' => $match,
            'results' => array_map(fn (array $r) => array_diff_key($r, ['reason' => true]), $results),
            'reason' => $matched ? null : ($match === self::ANY ? 'None of the conditions matched.' : $firstFailure['reason']),
        ];
    }

    /**
     * @throws InvalidAutomationConditionException
     */
    public function evaluate(AutomationCondition $condition, AutomationContext $context): bool
    {
        return $this->check($condition, $context)['passed'];
    }

    /**
     * @return array{label: string, actual: string, passed: bool, reason: string}
     *
     * @throws InvalidAutomationConditionException
     */
    public function check(AutomationCondition $condition, AutomationContext $context): array
    {
        [$field, $operator] = $this->resolve($condition);
        $raw = $condition->getAttributes()['value'] ?? null;
        $expected = $field->parse($raw);
        $actual = $this->actualValue($field, $context);
        $passed = $actual !== null && $this->compare($field, $operator, $actual, $expected, $context);
        $label = trim("{$field->label} {$operator->label()} ".($operator->needsValue() ? $field->describe($raw) : ''));

        return [
            'label' => $label,
            'actual' => $this->describeActual($field, $actual),
            'passed' => $passed,
            'reason' => $field->failureReason !== null && $operator === Op::IsFalse && $actual === true
                ? $field->failureReason
                : "Condition not met: {$label} (it was ".$this->describeActual($field, $actual).').',
        ];
    }

    /**
     * Check a condition without evaluating it (for builders and before saving).
     *
     * @throws InvalidAutomationConditionException
     */
    public function assertValid(string|C $type, string|Op $operator, mixed $value): void
    {
        $condition = new AutomationCondition;
        $condition->setRawAttributes([
            'type' => $type instanceof C ? $type->value : $type,
            'operator' => $operator instanceof Op ? $operator->value : $operator,
            'value' => $value,
        ]);

        [$field] = $this->resolve($condition);
        $field->parse($value);
    }

    /**
     * @return array{0: F, 1: Op}
     */
    private function resolve(AutomationCondition $condition): array
    {
        // Raw values, so a corrupted stored value is rejected instead of crashing the enum cast.
        $raw = $condition->getAttributes();
        $type = C::tryFrom((string) ($raw['type'] ?? '')) ?? throw new InvalidAutomationConditionException('Unknown condition type.');
        $operator = Op::tryFrom((string) ($raw['operator'] ?? '')) ?? throw new InvalidAutomationConditionException('Unknown condition operator.');
        $field = ConditionFieldRegistry::get($type);

        if (! in_array($operator, $field->operators(), true)) {
            throw new InvalidAutomationConditionException("The \"{$operator->label()}\" operator cannot be used with {$field->label}.");
        }

        return [$field, $operator];
    }

    private function actualValue(F $field, AutomationContext $context): string|int|float|bool|null
    {
        $customer = fn () => $context->customer();
        $conversation = fn () => $context->conversation();
        $estimate = fn () => $context->estimate();
        $lower = fn (?string $value) => $value === null || trim($value) === '' ? null : mb_strtolower(trim($value));

        return match ($field->type) {
            C::IntentEquals => $context->intent?->value,
            C::ConfidenceGreaterThan => $context->confidence,
            C::CustomerReplied => $this->customerReplied($context),
            C::CustomerStatusEquals => $customer()?->status?->value,
            C::CustomerHasEmail => $customer() === null ? null : (bool) filter_var($customer()->email, FILTER_VALIDATE_EMAIL),
            C::CustomerHasPhone => $customer() === null ? null : filled($customer()->phone),
            C::CustomerEmail => $lower($customer()?->email),
            C::CustomerCompany => $lower($customer()?->company),
            C::ConversationStatusEquals => $conversation()?->status?->value,
            C::ConversationPriority => $conversation() === null ? null : $this->priorities->forConversationModel($conversation())->value,
            C::ConversationIntent => $conversation()?->latest_intent?->value,
            C::ConversationSubject => $lower($conversation()?->subject),
            C::DaysSinceLastMessage => ($last = $conversation()?->last_message_at) === null ? null : (int) floor($last->diffInDays(now(), absolute: true)),
            C::EstimateStatusEquals => $estimate()?->status?->value,
            C::EstimateTotal => $estimate()?->cents('total'),
            C::EstimateTitle => $lower($estimate()?->title),
            C::EstimateValidUntil => $estimate()?->valid_until?->toDateString(),
            C::FollowUpStatus => $context->followUp()?->status?->value,
            C::FollowUpDueAt => ($followUp = $context->followUp()) === null ? null : $context->organization()?->localTime($followUp->due_at)->toDateString(),
        };
    }

    /**
     * Did the customer reply in the conversation after the trigger happened?
     */
    private function customerReplied(AutomationContext $context): ?bool
    {
        if ($context->conversation() === null || $context->occurredAt === null) {
            return null;
        }

        return Message::query()
            ->where('organization_id', $context->organizationId)
            ->where('conversation_id', $context->conversationId)
            ->where('direction', MessageDirection::Inbound)
            ->where('received_at', '>', $context->occurredAt)
            ->exists();
    }

    private function compare(F $field, Op $operator, string|int|float|bool $actual, string|int|float|null $expected, AutomationContext $context): bool
    {
        if ($field->dataType === F::DATE) {
            $expected = $this->resolveDate((string) $expected, $context);
        }

        $numeric = in_array($field->dataType, [F::NUMBER, F::MONEY, F::PERCENT], true);

        return match ($operator) {
            Op::Equals => $numeric ? (float) $actual === (float) $expected : $actual === $expected,
            Op::NotEquals => $numeric ? (float) $actual !== (float) $expected : $actual !== $expected,
            Op::GreaterThan => $actual > $expected,
            Op::GreaterThanOrEqual => $actual >= $expected,
            Op::LessThan => $actual < $expected,
            Op::LessThanOrEqual => $actual <= $expected,
            Op::Contains => str_contains((string) $actual, (string) $expected),
            Op::NotContains => ! str_contains((string) $actual, (string) $expected),
            Op::IsTrue => $actual === true,
            Op::IsFalse => $actual === false,
            // Dates are Y-m-d strings, so string comparison is date order.
            Op::Before => $actual < $expected,
            Op::After => $actual > $expected,
            Op::On => $actual === $expected,
            Op::OnOrBefore => $actual <= $expected,
            Op::OnOrAfter => $actual >= $expected,
        };
    }

    /**
     * "today", "today+3" and "today-3" in the organization's timezone; dates stay as they are.
     */
    private function resolveDate(string $value, AutomationContext $context): string
    {
        if (! preg_match('/^today([+-]\d{1,3})?$/', $value, $m)) {
            return $value;
        }

        $today = $context->organization()?->localNow() ?? now()->toImmutable();

        return $today->addDays((int) ($m[1] ?? 0))->toDateString();
    }

    private function describeActual(F $field, string|int|float|bool|null $actual): string
    {
        return match (true) {
            $actual === null => 'not available',
            is_bool($actual) => $actual ? 'yes' : 'no',
            $field->dataType === F::MONEY => Money::format((int) $actual),
            $field->dataType === F::PERCENT => round((float) $actual * 100).'%',
            $field->dataType === F::ENUM => $field->options[(string) $actual] ?? (string) $actual,
            default => (string) $actual,
        };
    }
}

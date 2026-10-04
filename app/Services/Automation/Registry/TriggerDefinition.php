<?php

namespace App\Services\Automation\Registry;

use App\Enums\Automation\AutomationTriggerType;

final readonly class TriggerDefinition
{
    /**
     * @param  list<Subject>  $provides  Records every run of this trigger has.
     */
    public function __construct(
        public AutomationTriggerType $type,
        public string $label,
        public string $description,
        public array $provides,
        public bool $available = true,
    ) {}

    public function key(): string
    {
        return $this->type->value;
    }

    public function provides(Subject $subject): bool
    {
        return in_array($subject, $this->provides, true);
    }

    /**
     * @param  list<Subject>  $subjects
     */
    public function providesAll(array $subjects): bool
    {
        return array_diff(array_map(fn (Subject $s) => $s->value, $subjects), array_map(fn (Subject $s) => $s->value, $this->provides)) === [];
    }

    /**
     * Condition fields this trigger supports.
     *
     * @return list<ConditionFieldDefinition>
     */
    public function conditionFields(): array
    {
        return array_values(array_filter(ConditionFieldRegistry::selectable(), fn (ConditionFieldDefinition $f) => $this->providesAll($f->requires)));
    }

    /**
     * Template variables available to this trigger's actions.
     *
     * @return list<VariableDefinition>
     */
    public function variables(): array
    {
        return array_values(array_filter(VariableRegistry::all(), fn (VariableDefinition $v) => $v->subject === null || $this->provides($v->subject)));
    }
}

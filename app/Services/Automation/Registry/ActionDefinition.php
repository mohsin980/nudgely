<?php

namespace App\Services\Automation\Registry;

use App\Enums\Automation\AutomationActionType;
use Closure;

final readonly class ActionDefinition
{
    /**
     * @param  list<ActionField>  $fields
     * @param  Closure(array<string, mixed>): list<Subject>  $requires  Records the action needs, given its settings.
     * @param  list<string>  $excludedTriggers  Triggers it can't be used with (e.g. to prevent loops).
     * @param  Closure(array<string, mixed>, ActionValidation): array<string, mixed>  $rules  Validates and normalizes the settings.
     */
    public function __construct(
        public AutomationActionType $type,
        public string $label,
        public string $description,
        public array $fields,
        public Closure $requires,
        public Closure $rules,
        public array $excludedTriggers = [],
        public bool $contactsCustomer = false,
    ) {}

    public function key(): string
    {
        return $this->type->value;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<Subject>
     */
    public function requires(array $configuration): array
    {
        return ($this->requires)($configuration);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function defaults(): array
    {
        return collect($this->fields)->mapWithKeys(fn (ActionField $f) => [$f->name => $f->default])->all();
    }

    public function supports(TriggerDefinition $trigger): bool
    {
        return ! in_array($trigger->key(), $this->excludedTriggers, true);
    }
}

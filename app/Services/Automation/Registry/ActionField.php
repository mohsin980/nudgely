<?php

namespace App\Services\Automation\Registry;

/**
 * One input of an action's form.
 *
 * Types: text, textarea, template (a {{variables}} template), select, user, checkbox.
 */
final readonly class ActionField
{
    /**
     * @param  array<string, string>  $options  For selects: value => label.
     * @param  array<string, string>  $showWhen  Show only when other fields have these values.
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public bool $required = false,
        public array $options = [],
        public ?string $help = null,
        public string|int|bool|null $default = null,
        public array $showWhen = [],
        public ?int $max = null,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function isShown(array $configuration): bool
    {
        foreach ($this->showWhen as $field => $value) {
            if ((string) ($configuration[$field] ?? '') !== $value) {
                return false;
            }
        }

        return true;
    }
}

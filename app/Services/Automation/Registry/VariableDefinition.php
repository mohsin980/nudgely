<?php

namespace App\Services\Automation\Registry;

final readonly class VariableDefinition
{
    /**
     * @param  ?Subject  $subject  The record it reads; null = always available (the business).
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?Subject $subject,
        public string $example,
    ) {}

    public function token(): string
    {
        return '{{'.$this->key.'}}';
    }
}

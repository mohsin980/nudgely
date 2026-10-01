<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * The sender address must belong exactly to the configured domain (sales@example.com for example.com).
 */
class SenderEmailMatchesDomain implements ValidationRule
{
    public function __construct(private readonly ?string $domain) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A missing domain is reported by the domain field's own rules.
        if (! is_string($value) || blank($this->domain)) {
            return;
        }

        $emailDomain = Str::afterLast(strtolower(trim($value)), '@');

        if ($emailDomain !== strtolower(trim($this->domain))) {
            $fail('The :attribute must be an address on '.strtolower(trim($this->domain)).'.');
        }
    }
}

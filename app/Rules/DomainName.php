<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare, fully-qualified domain name such as "example.com" (no scheme, path, port or trailing dot).
 */
class DomainName implements ValidationRule
{
    private const PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, trim($value))) {
            $fail('The :attribute must be a valid domain name, like example.com.');
        }
    }
}

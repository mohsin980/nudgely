<?php

namespace App\Exceptions\Automation;

use InvalidArgumentException;

/**
 * An automated email template uses a variable that is not allowed. The message is safe to show.
 */
class InvalidEmailTemplateException extends InvalidArgumentException
{
    public static function unsupported(string $variable): self
    {
        return new self('Unsupported variable {{'.mb_substr($variable, 0, 40).'}}.');
    }

    public static function unavailable(string $variable, string $reason): self
    {
        return new self('{{'.$variable.'}} cannot be used yet: '.$reason);
    }

    public static function missingEstimate(): self
    {
        return new self('This email uses estimate details, but there is no estimate for this customer conversation.');
    }

    public static function malformed(): self
    {
        return new self('The template has unmatched {{ or }} braces.');
    }
}

<?php

namespace App\Exceptions\Platform;

use RuntimeException;

/** A user-account change that cannot be carried out; the message is safe to show to the administrator. */
class UserAccountException extends RuntimeException
{
    public static function owner(): self
    {
        return new self('This person owns their business. Suspend the organization instead, or have the business transfer ownership first.');
    }

    public static function notActive(): self
    {
        return new self('Only active accounts can be deactivated.');
    }

    public static function notSuspended(): self
    {
        return new self('Only deactivated accounts can be reactivated.');
    }

    public static function invalidReason(int $min, int $max): self
    {
        return new self("A reason of {$min} to {$max} characters is required.");
    }
}

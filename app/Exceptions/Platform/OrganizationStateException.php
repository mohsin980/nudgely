<?php

namespace App\Exceptions\Platform;

use RuntimeException;

/** A suspension request that cannot be carried out; the message is safe to show to the administrator. */
class OrganizationStateException extends RuntimeException
{
    public static function alreadySuspended(): self
    {
        return new self('This organization is already suspended.');
    }

    public static function notSuspended(): self
    {
        return new self('This organization is not suspended.');
    }

    public static function ownOrganization(): self
    {
        return new self('You cannot suspend the organization your own account belongs to.');
    }

    public static function invalidReason(int $min, int $max): self
    {
        return new self("A reason of {$min} to {$max} characters is required.");
    }
}

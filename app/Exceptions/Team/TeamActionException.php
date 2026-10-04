<?php

namespace App\Exceptions\Team;

use RuntimeException;

/**
 * A team or invitation action that can't be done; the message is safe to show.
 */
class TeamActionException extends RuntimeException
{
    public const LAST_OWNER = 'An organization must always have an owner.';

    public static function lastOwner(): self
    {
        return new self(self::LAST_OWNER);
    }

    public static function notFound(): self
    {
        return new self('Team member not found.');
    }
}

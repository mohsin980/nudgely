<?php

namespace App\Exceptions\FollowUps;

use RuntimeException;

/**
 * A follow-up request that can't be carried out (bad date, wrong status, other tenant). Safe to show.
 */
class InvalidFollowUpException extends RuntimeException
{
    public static function transition(string $from, string $to): self
    {
        return new self("This follow-up is {$from} and can't be {$to}.");
    }
}

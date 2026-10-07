<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * A billing action that can't be done; the message is safe to show.
 */
class BillingException extends RuntimeException
{
    public static function unknownPlan(string $key): self
    {
        return new self("Unknown plan [{$key}].");
    }

    public static function invalidConfiguration(string $reason): self
    {
        return new self("Billing configuration is invalid: {$reason}");
    }
}

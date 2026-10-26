<?php

namespace App\Exceptions\Platform;

use RuntimeException;

/** A plan change that is invalid or not allowed; the message is safe to show to the administrator. */
class PlanSettingsException extends RuntimeException
{
    public static function unknownPlan(): self
    {
        return new self('That plan does not exist. Plans are defined in the billing configuration.');
    }

    public static function noChanges(): self
    {
        return new self('Nothing was changed.');
    }

    public static function invalidReason(int $min, int $max): self
    {
        return new self("A reason of {$min} to {$max} characters is required.");
    }
}

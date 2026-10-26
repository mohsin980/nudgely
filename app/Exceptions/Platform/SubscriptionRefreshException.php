<?php

namespace App\Exceptions\Platform;

use RuntimeException;

/** A subscription refresh that could not be done; the message is safe to show to the administrator. */
class SubscriptionRefreshException extends RuntimeException
{
    public static function localOnly(): self
    {
        return new self('This subscription has no external billing provider to refresh from.');
    }

    public static function tooSoon(): self
    {
        return new self('This subscription was refreshed a moment ago. Wait about 30 seconds and try again.');
    }

    public static function providerFailed(): self
    {
        return new self('The billing provider could not be reached or rejected the request. The stored record is unchanged.');
    }
}

<?php

namespace App\Exceptions\Outreach;

use RuntimeException;

/**
 * A send that didn't go through. A transient failure (network, rate limit, 5xx) is retried by the job; a permanent
 * one (rejected address, bad template, missing credentials) is recorded as failed and not retried.
 */
class OutreachSendException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}

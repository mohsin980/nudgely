<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;

/**
 * A call to Meta failed. The message is for logs only: it never contains a token, a phone number or the message body.
 */
class WhatsAppApiException extends RuntimeException
{
    public function __construct(string $message, private readonly bool $transient = false)
    {
        parent::__construct($message);
    }

    /**
     * A failure worth retrying later (network trouble, rate limit, Meta server error), not a rejected request.
     */
    public function isTransient(): bool
    {
        return $this->transient;
    }
}

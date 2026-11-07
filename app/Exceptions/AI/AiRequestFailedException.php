<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * No model in the chain gave a usable answer. The message is safe to store and show: it never contains
 * prompts, customer content, raw AI output or credentials.
 */
class AiRequestFailedException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const INVALID_OUTPUT = 'invalid_output';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Worth trying again later (every model was busy, limited or down, not misconfigured).
     */
    public function isTransient(): bool
    {
        return $this->reason === self::UNAVAILABLE;
    }
}

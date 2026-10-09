<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * A classification attempt failed. The message is safe to store and show: it never
 * contains prompts, customer content, raw AI output or credentials.
 */
class ClassificationFailedException extends RuntimeException
{
    private function __construct(string $message, public readonly bool $transient)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('AI classification is not configured.', transient: false);
    }

    public static function unavailable(string $reason): self
    {
        return new self("AI provider unavailable: {$reason}.", transient: true);
    }

    public static function rejected(string $reason): self
    {
        return new self("AI provider rejected the request: {$reason}.", transient: false);
    }

    public static function invalidOutput(string $reason): self
    {
        return new self("AI returned an invalid classification: {$reason}.", transient: false);
    }
}

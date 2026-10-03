<?php

namespace App\Exceptions\Conversations;

use RuntimeException;

/**
 * A conversation action that can't be done (validation, wrong organization, email not allowed).
 * The message is safe to show; provider details never reach it.
 */
class ConversationActionException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  Field => message, for forms.
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(reset($errors), $errors);
    }
}

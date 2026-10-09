<?php

namespace App\Exceptions\Estimates;

use RuntimeException;

/**
 * An estimate action that can't be done (validation, wrong status, sending not allowed).
 * The message is safe to show; provider details never reach it.
 */
class EstimateException extends RuntimeException
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

    public static function notFound(): self
    {
        return new self('Estimate not found.');
    }
}

<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;

/**
 * A WhatsApp action a person asked for cannot be done. The message is written for the person, not for logs.
 */
class WhatsAppException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Conversation not found.');
    }

    public static function notConnected(): self
    {
        return new self('Connect a WhatsApp Business number in Settings first.');
    }

    public static function noPhone(): self
    {
        return new self('This customer has no phone number. Add one to their profile first.');
    }

    public static function windowClosed(): self
    {
        return new self('The customer has not written in the last 24 hours. Send the approved follow-up message instead.');
    }

    public static function invalidBody(int $maxLength): self
    {
        return new self('Enter a message of up to '.$maxLength.' characters.');
    }

    public static function numberInUse(): self
    {
        return new self('This WhatsApp number is already connected to another account.');
    }

    public static function signupFailed(): self
    {
        return new self('WhatsApp could not be connected. Try again, or contact support if it keeps failing.');
    }
}

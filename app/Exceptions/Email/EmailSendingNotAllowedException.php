<?php

namespace App\Exceptions\Email;

use RuntimeException;

/**
 * The organization is not currently allowed to send email (no verified sender, mismatch, bad input).
 *
 * The message is safe to show to users. These failures are permanent: retrying will not help.
 */
class EmailSendingNotAllowedException extends RuntimeException
{
    public static function noSender(): self
    {
        return new self('Set up and verify a business email in Email Settings before sending emails.');
    }

    public static function notVerified(): self
    {
        return new self('Your business email domain must be verified before emails can be sent.');
    }

    public static function senderMismatch(): self
    {
        return new self('The sender email does not belong to the verified domain.');
    }

    public static function connectionRemoved(): self
    {
        return new self('The business email this message was going to be sent from no longer exists.');
    }

    public static function invalidAddress(string $field): self
    {
        return new self("The {$field} is not a valid email address.");
    }
}

<?php

namespace App\Enums;

enum EmailProvider: string
{
    case Postmark = 'postmark';
    case Resend = 'resend';
    case Mailgun = 'mailgun';
    case Sendgrid = 'sendgrid';
    case Custom = 'custom';

    /**
     * The configured provider for new connections, falling back to Postmark.
     */
    public static function default(): self
    {
        return self::tryFrom((string) config('email.provider')) ?? self::Postmark;
    }
}

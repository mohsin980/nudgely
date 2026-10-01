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
     * The provider assigned to connections created before provider selection exists.
     */
    public static function default(): self
    {
        return self::Postmark;
    }
}

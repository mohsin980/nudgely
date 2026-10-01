<?php

namespace App\Enums;

enum EmailProvider: string
{
    case Postmark = 'postmark';
    case Resend = 'resend';
    case Mailgun = 'mailgun';
    case Sendgrid = 'sendgrid';
    case Custom = 'custom';
}

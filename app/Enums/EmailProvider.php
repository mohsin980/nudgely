<?php

namespace App\Enums;

enum EmailProvider: string
{
    // Domain-verified providers: the business proves it owns a sending domain (DNS records).
    case Postmark = 'postmark';
    case Resend = 'resend';
    case Mailgun = 'mailgun';
    case Sendgrid = 'sendgrid';
    case Custom = 'custom';

    // Account-based senders: the business connects an existing mailbox. No domain or DNS records are needed.
    case Gmail = 'gmail';
    case Microsoft = 'microsoft';
    case Smtp = 'smtp';

    /**
     * The configured provider for new domain connections, falling back to Postmark.
     */
    public static function default(): self
    {
        return self::tryFrom((string) config('email.provider')) ?? self::Postmark;
    }

    public function isAccountBased(): bool
    {
        return in_array($this, [self::Gmail, self::Microsoft, self::Smtp], true);
    }

    /**
     * @return list<self>
     */
    public static function domainVerified(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => ! $provider->isAccountBased()));
    }
}

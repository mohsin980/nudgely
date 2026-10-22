<?php

namespace App\Services\Email;

use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Services\Email\Data\EmailSendResult;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\Providers\SmtpEmailProvider;

/**
 * Sends through a business's own mailbox, using the credentials stored on that connection.
 *
 * Everything is keyed by the connection, which EmailService has already checked belongs to the sending organization.
 */
class AccountEmailSender
{
    public function __construct(private readonly SmtpEmailProvider $smtp) {}

    /**
     * @throws EmailProviderException
     */
    public function send(EmailConnection $connection, OutboundEmail $email): EmailSendResult
    {
        return match ($connection->provider) {
            EmailProvider::Smtp => $this->smtp->send($this->smtpSettings($connection), $email),
            default => throw EmailProviderException::notConfigured("Account sender [{$connection->provider->value}] is not available yet."),
        };
    }

    private function smtpSettings(EmailConnection $connection): SmtpSettings
    {
        $credentials = $connection->credentials;

        if (! is_array($credentials) || $credentials === []) {
            throw EmailProviderException::notConfigured('The SMTP connection has no stored credentials.');
        }

        return SmtpSettings::fromCredentials($credentials);
    }
}

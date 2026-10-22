<?php

namespace App\Services\Email\Providers;

use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Data\EmailSendResult;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Data\SmtpSettings;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through a business's own SMTP account (Gmail or Outlook app passwords, or the business's mail server).
 *
 * Encryption is required unless the server is a private relay the administrator allowed. Hosts that resolve to
 * private or local addresses are refused by default, so the server cannot be used to probe its own network.
 * Errors name a category only; the password, the username and the raw server reply are never included.
 */
class SmtpEmailProvider
{
    private const TIMEOUT_SECONDS = 15;

    /** Test a connection without sending. Throws EmailProviderException with a safe message. */
    public function test(SmtpSettings $settings): void
    {
        $transport = $this->transport($settings);

        try {
            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $e) {
            throw $this->failure($e);
        }
    }

    public function send(SmtpSettings $settings, OutboundEmail $email): EmailSendResult
    {
        $message = (new Email)
            ->from(new Address($email->fromEmail, $email->fromName))
            ->to(new Address($email->toEmail, $email->toName ?? ''))
            ->subject($email->subject)
            ->text($email->textBody ?? '')
            ->html($email->htmlBody ?? '');

        if ($email->replyTo !== null) {
            $message->replyTo($email->replyTo);
        }

        $transport = $this->transport($settings);

        try {
            $sent = $transport->send($message);
        } catch (TransportExceptionInterface $e) {
            // The server may have accepted the message before the connection failed, so only failures
            // before the message was handed over are safe to retry; the category decides which.
            throw $this->failure($e);
        } finally {
            $this->close($transport);
        }

        return EmailSendResult::sent(trim((string) $sent->getMessageId(), '<>'));
    }

    protected function transport(SmtpSettings $settings): EsmtpTransport
    {
        $this->assertAllowedHost($settings->host, $settings->encryption);

        // ssl: TLS from the first byte. tls: plain start, then STARTTLS, which is required (credentials are never sent unencrypted).
        $transport = new EsmtpTransport($settings->host, $settings->port, $settings->encryption === SmtpSettings::ENCRYPTION_SSL);
        $transport->setUsername($settings->username);
        $transport->setPassword($settings->password);
        $transport->setAutoTls($settings->encryption !== SmtpSettings::ENCRYPTION_NONE);
        $transport->setRequireTls($settings->encryption === SmtpSettings::ENCRYPTION_TLS);
        $transport->setTimeout(self::TIMEOUT_SECONDS);

        return $transport;
    }

    private function close(EsmtpTransport $transport): void
    {
        try {
            $transport->stop();
        } catch (TransportExceptionInterface) {
            // Already closed or never opened: nothing to release.
        }
    }

    /**
     * @throws EmailProviderException
     */
    private function assertAllowedHost(string $host, string $encryption): void
    {
        $allowPrivate = (bool) config('email.smtp.allow_private_hosts', false);

        if ($encryption === SmtpSettings::ENCRYPTION_NONE && ! $allowPrivate) {
            throw new EmailProviderException('Unencrypted SMTP is disabled on this server.', EmailProviderException::NOT_CONFIGURED,
                'Use TLS or SSL for this mail server. Unencrypted connections are not allowed.');
        }

        if ($allowPrivate) {
            return;
        }

        $addresses = gethostbynamel($host);

        if ($addresses === false || $addresses === []) {
            throw new EmailProviderException("SMTP host [{$host}] did not resolve.", EmailProviderException::UNAVAILABLE,
                'We couldn\'t find that mail server. Check the server name and try again.');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new EmailProviderException("SMTP host [{$host}] resolves to a private address.", EmailProviderException::REJECTED,
                    'That server name points to a private network. Enter the public name of your mail server.');
            }
        }
    }

    private function failure(TransportExceptionInterface $e): EmailProviderException
    {
        $detail = $e::class; // the class only: the message can contain server replies

        return match (true) {
            (bool) preg_match('/\b535\b|authentication|auth(orization)? failed|credentials/i', $e->getMessage()) => new EmailProviderException(
                "SMTP authentication failed ({$detail}).", EmailProviderException::REAUTH_REQUIRED,
                'The username or password was not accepted. Check them, or create an app password if your provider requires one.'),
            (bool) preg_match('/tls|ssl|certificate|starttls|handshake/i', $e->getMessage()) => new EmailProviderException(
                "SMTP encryption could not be established ({$detail}).", EmailProviderException::REJECTED,
                'We couldn\'t set up an encrypted connection. Try a different encryption setting or port, as your provider advises.'),
            default => new EmailProviderException(
                "SMTP server unreachable ({$detail}).", EmailProviderException::UNAVAILABLE,
                'We couldn\'t reach that mail server. Check the server name and port, then try again.'),
        };
    }
}

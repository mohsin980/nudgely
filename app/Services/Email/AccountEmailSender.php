<?php

namespace App\Services\Email;

use App\Enums\EmailProvider;
use App\Enums\Team\NotificationType;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Services\Email\Data\EmailSendResult;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\OAuth\OAuthMailClient;
use App\Services\Email\Providers\SmtpEmailProvider;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through a business's own mailbox, using the credentials stored on that connection.
 *
 * Everything is keyed by the connection, which EmailService has already checked belongs to the sending organization.
 * A revoked Gmail or Microsoft grant marks the connection as needing reconnection and is not retried.
 */
class AccountEmailSender
{
    public function __construct(
        private readonly SmtpEmailProvider $smtp,
        private readonly OAuthMailClient $oauth,
    ) {}

    /**
     * @throws EmailProviderException
     */
    public function send(EmailConnection $connection, OutboundEmail $email): EmailSendResult
    {
        return match ($connection->provider) {
            EmailProvider::Smtp => $this->smtpSend($connection, $email),
            EmailProvider::Gmail, EmailProvider::Microsoft => $this->oauthSend($connection, $email),
            default => throw EmailProviderException::notConfigured("Account sender [{$connection->provider->value}] is not available."),
        };
    }

    /**
     * @throws EmailProviderException
     */
    private function oauthSend(EmailConnection $connection, OutboundEmail $email): EmailSendResult
    {
        try {
            $accessToken = $this->accessToken($connection);

            if ($connection->provider === EmailProvider::Gmail) {
                return EmailSendResult::sent($this->oauth->sendGmail($accessToken, $this->mime($email)));
            }

            $this->oauth->sendMicrosoft($accessToken, $this->graphMessage($email));

            // Graph returns no message ID. A local ID keeps the record unique; delivery is not inferred from it.
            return EmailSendResult::sent('graph-'.Str::uuid());
        } catch (EmailProviderException $e) {
            if ($e->reason === EmailProviderException::REAUTH_REQUIRED) {
                $this->markReauthRequired($connection);
            }

            throw $e;
        }
    }

    /**
     * @throws EmailProviderException
     */
    private function smtpSend(EmailConnection $connection, OutboundEmail $email): EmailSendResult
    {
        try {
            return $this->smtp->send($this->smtpSettings($connection), $email);
        } catch (EmailProviderException $e) {
            if ($e->reason === EmailProviderException::REAUTH_REQUIRED) {
                $this->markReauthRequired($connection);
            }

            throw $e;
        }
    }

    /**
     * Exchanges the stored refresh token for an access token. Microsoft rotates refresh tokens, so a new one is kept.
     *
     * @throws EmailProviderException
     */
    public function accessToken(EmailConnection $connection): string
    {
        $refreshToken = $connection->credentials['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw EmailProviderException::reauthRequired('No refresh token is stored for this connection.');
        }

        $tokens = $this->oauth->refresh($connection->provider, $refreshToken);

        if ($tokens['refresh_token'] !== null && $tokens['refresh_token'] !== $refreshToken) {
            $connection->forceFill(['credentials' => ['refresh_token' => $tokens['refresh_token']]])->save();
        }

        return $tokens['access_token'];
    }

    /**
     * Flags the account once. Later failures for the same account change nothing and send no second notice.
     */
    public function markReauthRequired(EmailConnection $connection): void
    {
        if ($connection->connection_status !== EmailConnection::STATUS_ACTIVE) {
            return;
        }

        $connection->forceFill([
            'connection_status' => EmailConnection::STATUS_REAUTH_REQUIRED,
            'status_message' => 'Access was revoked or expired. Reconnect this account in Email settings.',
        ])->save();

        // Resolved here, not in the constructor: TeamNotifier depends on EmailService, which depends on this class.
        $organization = Organization::query()->find($connection->organization_id);

        if ($organization !== null) {
            app(TeamNotifier::class)->notify(
                $organization,
                NotificationType::EmailAccountNeedsReconnect,
                app(TeamDirectory::class)->leaders($organization->id),
                "{$connection->sender_email} needs to be reconnected, so automated emails from it are paused. Reconnect it in Email settings.",
                route('settings.email'),
                "email-reconnect:{$connection->id}",
                null,
                ['connection_id' => $connection->id],
            );
        }
    }

    private function mime(OutboundEmail $email): string
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

        foreach ($email->headers as $name => $value) {
            $message->getHeaders()->addTextHeader($name, $value);
        }

        return $message->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function graphMessage(OutboundEmail $email): array
    {
        $html = $email->htmlBody !== null && $email->htmlBody !== '';

        // Graph only takes custom headers that start with "x-"; others (e.g. List-Unsubscribe) are left out.
        $custom = collect($email->headers)
            ->filter(fn (string $value, string $name) => str_starts_with(strtolower($name), 'x-'))
            ->map(fn (string $value, string $name) => ['name' => $name, 'value' => $value])
            ->values()
            ->all();

        return array_filter([
            'subject' => $email->subject,
            'body' => ['contentType' => $html ? 'HTML' : 'Text', 'content' => $html ? $email->htmlBody : (string) $email->textBody],
            'toRecipients' => [['emailAddress' => array_filter(['address' => $email->toEmail, 'name' => $email->toName])]],
            'replyTo' => $email->replyTo !== null ? [['emailAddress' => ['address' => $email->replyTo]]] : null,
            'internetMessageHeaders' => $custom ?: null,
        ]);
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

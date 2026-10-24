<?php

namespace App\Services\Email\OAuth;

use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP calls to Google and Microsoft for account-based sending: authorization links, token exchange and refresh,
 * the account's own address, and sending.
 *
 * Least privilege: Google gets gmail.send plus identity; Microsoft gets Mail.Send plus identity and offline access.
 * Tokens and raw provider responses are never put into exception messages.
 */
class OAuthMailClient
{
    private const GOOGLE_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';

    private const GOOGLE_USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const GOOGLE_REVOKE = 'https://oauth2.googleapis.com/revoke';

    private const GMAIL_SEND = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const GMAIL_MESSAGES = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';

    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** gmail.readonly lets us find bounce notices in the business's own mailbox. */
    private const GOOGLE_SCOPES = 'openid email https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly';

    private const MICROSOFT_SCOPES = 'openid email offline_access User.Read Mail.Send Mail.Read';

    public function authorizeUrl(EmailProvider $provider, string $state, string $challenge, string $redirectUri): string
    {
        $common = [
            'client_id' => $this->clientId($provider),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return match ($provider) {
            EmailProvider::Gmail => self::GOOGLE_AUTHORIZE.'?'.http_build_query($common + [
                'scope' => self::GOOGLE_SCOPES,
                'access_type' => 'offline',
                'prompt' => 'consent',
            ]),
            EmailProvider::Microsoft => $this->microsoftAuthority().'/authorize?'.http_build_query($common + [
                'scope' => self::MICROSOFT_SCOPES,
                'response_mode' => 'query',
                'prompt' => 'select_account',
            ]),
            default => throw EmailProviderException::notConfigured('OAuth is only used for Gmail and Microsoft.'),
        };
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: ?string}
     *
     * @throws EmailProviderException
     */
    public function exchangeCode(EmailProvider $provider, string $code, string $verifier, string $redirectUri): array
    {
        return $this->tokenRequest($provider, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ], onRejected: fn () => EmailProviderException::rejected('The provider did not accept the connection code.'));
    }

    /**
     * Refreshes an access token. A revoked or expired grant is reported as reauth-required, never retried.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     *
     * @throws EmailProviderException
     */
    public function refresh(EmailProvider $provider, string $refreshToken): array
    {
        return $this->tokenRequest($provider, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], onRejected: fn (Response $response) => $this->isGrantRevoked($response)
            ? EmailProviderException::reauthRequired('The stored refresh token was revoked or expired.')
            : EmailProviderException::rejected('The provider refused to refresh the access token.'));
    }

    /**
     * Asks Google to revoke the grant, so the app can no longer send from the account. Returns false when Google
     * refused or could not be reached. Microsoft offers no revocation for this sign-in, so it always returns false
     * and the business removes the app from its Microsoft account instead. Never throws.
     */
    public function revoke(EmailProvider $provider, string $refreshToken): bool
    {
        if ($provider !== EmailProvider::Gmail) {
            return false;
        }

        try {
            return Http::asForm()->timeout($this->timeout())->post(self::GOOGLE_REVOKE, ['token' => $refreshToken])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * The address the account signs in with, and whether the provider has verified it.
     *
     * @return array{email: string, verified: bool}
     *
     * @throws EmailProviderException
     */
    public function identity(EmailProvider $provider, string $accessToken): array
    {
        $response = $this->send(fn () => $this->request($accessToken)->get(
            $provider === EmailProvider::Gmail ? self::GOOGLE_USERINFO : self::GRAPH.'/me',
            $provider === EmailProvider::Gmail ? [] : ['$select' => 'mail,userPrincipalName'],
        ));

        if ($provider === EmailProvider::Gmail) {
            $email = strtolower((string) $response->json('email', ''));

            if ($email === '' || $response->json('email_verified') !== true) {
                throw EmailProviderException::rejected('The Google account has no verified email address.');
            }

            return ['email' => $email, 'verified' => true];
        }

        $email = strtolower((string) ($response->json('mail') ?: $response->json('userPrincipalName', '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw EmailProviderException::rejected('The Microsoft account has no email address.');
        }

        return ['email' => $email, 'verified' => true];
    }

    /**
     * Sends a complete MIME message through Gmail. Returns the Gmail message ID.
     *
     * @throws EmailProviderException
     */
    public function sendGmail(string $accessToken, string $rawMime): string
    {
        $response = $this->send(fn () => $this->request($accessToken)->post(self::GMAIL_SEND, [
            'raw' => rtrim(strtr(base64_encode($rawMime), '+/', '-_'), '='),
        ]));

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw EmailProviderException::unexpectedResponse('Gmail accepted the request without a message ID.');
        }

        return $id;
    }

    /**
     * Sends through Microsoft Graph. The API returns no message ID; the caller records its own.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws EmailProviderException
     */
    public function sendMicrosoft(string $accessToken, array $message): void
    {
        $this->send(fn () => $this->request($accessToken)->post(self::GRAPH.'/me/sendMail', [
            'message' => $message,
            'saveToSentItems' => true,
        ]));
    }

    /**
     * Recent mailbox messages that may be bounce notices, as provider message IDs.
     *
     * @return list<string>
     *
     * @throws EmailProviderException
     */
    public function listBounceCandidates(EmailProvider $provider, string $accessToken, int $days): array
    {
        return match ($provider) {
            EmailProvider::Gmail => $this->gmailBounceCandidates($accessToken, $days),
            EmailProvider::Microsoft => $this->microsoftBounceCandidates($accessToken, $days),
            default => throw EmailProviderException::notConfigured('Bounce notices are read from Gmail and Outlook only.'),
        };
    }

    /**
     * The full MIME message of one mailbox message.
     *
     * @throws EmailProviderException
     */
    public function fetchRaw(EmailProvider $provider, string $accessToken, string $providerMessageId): string
    {
        if ($provider === EmailProvider::Gmail) {
            $response = $this->send(fn () => $this->request($accessToken)->get(self::GMAIL_MESSAGES.'/'.rawurlencode($providerMessageId), ['format' => 'raw']));
            $raw = (string) $response->json('raw', '');

            return (string) base64_decode(strtr($raw, '-_', '+/'), true);
        }

        $response = $this->send(fn () => Http::withToken($accessToken)->withHeaders(['Accept' => 'message/rfc822'])->timeout($this->timeout())
            ->get(self::GRAPH.'/me/messages/'.rawurlencode($providerMessageId).'/$value'));

        return $response->body();
    }

    /**
     * @return list<string>
     *
     * @throws EmailProviderException
     */
    private function gmailBounceCandidates(string $accessToken, int $days): array
    {
        $response = $this->send(fn () => $this->request($accessToken)->get(self::GMAIL_MESSAGES, [
            'q' => "newer_than:{$days}d (from:mailer-daemon OR from:postmaster OR subject:(undelivered OR \"delivery status notification\"))",
            'maxResults' => 50,
        ]));

        return collect($response->json('messages', []))->pluck('id')->filter(fn ($id) => is_string($id) && $id !== '')->values()->all();
    }

    /**
     * Graph has no query for "from a mailer daemon", so recent messages are fetched and matched here.
     *
     * @return list<string>
     *
     * @throws EmailProviderException
     */
    private function microsoftBounceCandidates(string $accessToken, int $days): array
    {
        $since = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d\TH:i:s\Z');

        $response = $this->send(fn () => $this->request($accessToken)->get(self::GRAPH.'/me/messages', [
            '$filter' => "receivedDateTime ge {$since}",
            '$select' => 'id,from,subject',
            '$orderby' => 'receivedDateTime desc',
            '$top' => 50,
        ]));

        return collect($response->json('value', []))
            ->filter(fn (array $message) => $this->looksLikeBounce($message))
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function looksLikeBounce(array $message): bool
    {
        $from = strtolower((string) data_get($message, 'from.emailAddress.address', ''));
        $subject = strtolower((string) ($message['subject'] ?? ''));

        return str_contains($from, 'mailer-daemon')
            || str_contains($from, 'postmaster')
            || str_contains($subject, 'undeliverable')
            || str_contains($subject, 'undelivered')
            || str_contains($subject, 'delivery status notification');
    }

    /**
     * @param  array<string, string>  $form
     * @param  callable(Response): EmailProviderException  $onRejected
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: ?string}
     *
     * @throws EmailProviderException
     */
    private function tokenRequest(EmailProvider $provider, array $form, callable $onRejected): array
    {
        $form += [
            'client_id' => $this->clientId($provider),
            'client_secret' => $this->clientSecret($provider),
        ];

        if ($provider === EmailProvider::Microsoft) {
            $form['scope'] = self::MICROSOFT_SCOPES;
        }

        $url = $provider === EmailProvider::Gmail ? self::GOOGLE_TOKEN : $this->microsoftAuthority().'/token';

        $response = $this->call(fn () => Http::asForm()->acceptJson()->timeout($this->timeout())->post($url, $form));

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $onRejected($response);
        }

        return [
            'access_token' => $token,
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_in' => (int) $response->json('expires_in', 3600),
            // The permissions the business actually granted (space-separated); they can be fewer than requested.
            'scope' => is_string($response->json('scope')) ? $response->json('scope') : null,
        ];
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws EmailProviderException
     */
    private function send(callable $call): Response
    {
        $response = $this->call($call);

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 || $status === 403 => EmailProviderException::reauthRequired("Provider rejected the access token (HTTP {$status})."),
            $status === 429 || $status >= 500 => EmailProviderException::unavailable("Provider temporarily unavailable (HTTP {$status})."),
            default => EmailProviderException::rejected("Provider rejected the message (HTTP {$status})."),
        };
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws EmailProviderException
     */
    private function call(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw EmailProviderException::unavailable('The email provider could not be reached.');
        }
    }

    private function isGrantRevoked(Response $response): bool
    {
        return in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true);
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout($this->timeout());
    }

    private function timeout(): int
    {
        return (int) config('email.oauth.timeout', 15);
    }

    private function microsoftAuthority(): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode((string) config('email.oauth.microsoft.tenant', 'common')).'/oauth2/v2.0';
    }

    private function clientId(EmailProvider $provider): string
    {
        return $this->credential($provider, 'client_id');
    }

    private function clientSecret(EmailProvider $provider): string
    {
        return $this->credential($provider, 'client_secret');
    }

    /**
     * @throws EmailProviderException when the server has no OAuth client for this provider
     */
    private function credential(EmailProvider $provider, string $key): string
    {
        $name = $provider === EmailProvider::Gmail ? 'google' : 'microsoft';
        $value = (string) config("email.oauth.{$name}.{$key}", '');

        if ($value === '') {
            throw EmailProviderException::notConfigured("OAuth client [{$name}] is not configured.");
        }

        return $value;
    }
}

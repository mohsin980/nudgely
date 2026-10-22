<?php

namespace App\Services\Email\OAuth;

use App\Enums\EmailProvider;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One-time OAuth state and PKCE verifier, kept in the user's server-side session.
 *
 * The state is bound to the provider, the organization and the user who started the flow. It is consumed on
 * first use, so a replayed or forged callback finds nothing. The code verifier never leaves the server.
 */
class OAuthStateStore
{
    private const TTL_SECONDS = 600;

    private const KEY = 'email_oauth_pending';

    /**
     * @return array{state: string, challenge: string}
     */
    public function begin(EmailProvider $provider, int $organizationId, int $userId): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        Session::put(self::KEY, [
            'state' => $state,
            'provider' => $provider->value,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'verifier' => $verifier,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);

        return ['state' => $state, 'challenge' => $this->challengeFor($verifier)];
    }

    /**
     * Returns the verifier when the callback matches the pending flow, and forgets the flow either way.
     *
     * @throws InvalidArgumentException when the state is missing, reused, expired or for another account
     */
    public function consume(EmailProvider $provider, string $state, int $organizationId, int $userId): string
    {
        $pending = Session::pull(self::KEY);

        if (! is_array($pending) || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            throw new InvalidArgumentException('This connection link has expired or was already used. Start again from Email settings.');
        }

        if (($pending['provider'] ?? null) !== $provider->value
            || (int) ($pending['organization_id'] ?? 0) !== $organizationId
            || (int) ($pending['user_id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('This connection link was started by a different account. Start again from Email settings.');
        }

        if (now()->getTimestamp() > (int) ($pending['expires_at'] ?? 0)) {
            throw new InvalidArgumentException('This connection link has expired. Start again from Email settings.');
        }

        return (string) $pending['verifier'];
    }

    /**
     * PKCE S256: the challenge is the URL-safe base64 of the SHA-256 of the verifier.
     */
    public function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}

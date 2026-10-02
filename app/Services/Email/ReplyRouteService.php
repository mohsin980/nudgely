<?php

namespace App\Services\Email;

use App\Models\Conversation;
use App\Models\EmailReplyRoute;

/**
 * Issues and resolves opaque Reply-To addresses (reply+<token>@<inbound domain>).
 *
 * Tokens are 160-bit random lowercase hex (safe if a mail server lowercases the
 * local part). Only their SHA-256 hash and last 4 characters are stored.
 */
class ReplyRouteService
{
    private const TOKEN_PATTERN = '[a-f0-9]{40}';

    /**
     * Create a new reply route for the conversation and return its Reply-To address.
     */
    public function createFor(Conversation $conversation): string
    {
        $token = bin2hex(random_bytes(20));
        $ttlDays = config('email.inbound.reply_route_ttl_days');

        $route = new EmailReplyRoute;
        $route->forceFill([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'token_hash' => $this->hash($token),
            'token_last4' => substr($token, -4),
            'active' => true,
            'expires_at' => filled($ttlDays) ? now()->addDays((int) $ttlDays) : null,
        ])->save();

        return 'reply+'.$token.'@'.$this->domain();
    }

    /**
     * Find the reply token among an email's recipients, accepting only our inbound domain.
     *
     * @param  list<string>  $recipients
     */
    public function extractToken(array $recipients): ?string
    {
        $pattern = '/^reply\+('.self::TOKEN_PATTERN.')@'.preg_quote($this->domain(), '/').'$/';

        foreach ($recipients as $recipient) {
            if (preg_match($pattern, strtolower(trim($recipient)), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * The active, unexpired route for a token, or null.
     */
    public function resolve(string $token): ?EmailReplyRoute
    {
        if (! preg_match('/^'.self::TOKEN_PATTERN.'$/', $token)) {
            return null;
        }

        $hash = $this->hash($token);
        $route = EmailReplyRoute::query()->usable()->where('token_hash', $hash)->first();

        // The lookup is by hash; compare again in constant time as defence in depth.
        return $route !== null && hash_equals($route->token_hash, $hash) ? $route : null;
    }

    public function replyAddressFor(string $token): string
    {
        return 'reply+'.$token.'@'.$this->domain();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function domain(): string
    {
        return strtolower((string) config('email.inbound.reply_domain'));
    }
}

<?php

namespace App\Services\Email\Bounces;

/**
 * Identifies one of our outbound messages inside a bounce notice, without trusting the notice.
 *
 * The token is the message ID plus an HMAC, so a notice can only point at a message we actually sent. It travels
 * in the X-QuoteFlow-Message header (copied into bounce notices) and, for SMTP, in the bounce address.
 */
final class BounceTokens
{
    public const HEADER = 'X-QuoteFlow-Message';

    public static function forMessage(int $messageId): string
    {
        return $messageId.'.'.self::signature($messageId);
    }

    public static function messageIdFrom(string $token): ?int
    {
        if (! preg_match('/^(\d{1,18})\.([a-f0-9]{16})$/', trim($token), $matches)) {
            return null;
        }

        $messageId = (int) $matches[1];

        return hash_equals(self::signature($messageId), $matches[2]) ? $messageId : null;
    }

    private static function signature(int $messageId): string
    {
        return substr(hash_hmac('sha256', 'outbound-message:'.$messageId, (string) config('app.key')), 0, 16);
    }
}

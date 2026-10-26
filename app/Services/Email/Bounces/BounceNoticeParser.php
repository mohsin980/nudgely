<?php

namespace App\Services\Email\Bounces;

/**
 * Reads the parts of a bounce notice that matter: which of our messages it is about, and whether the failure is permanent.
 */
final class BounceNoticeParser
{
    /**
     * The enhanced status code, e.g. "5.1.1". The class is the first digit: 5 is permanent, 4 is temporary.
     */
    public function statusCode(string $text): ?string
    {
        if (preg_match('/^\s*Status:\s*([245]\.\d{1,3}\.\d{1,3})\b/mi', $text, $matches)) {
            return $matches[1];
        }

        return preg_match('/(?<![\d.])([245]\.\d{1,3}\.\d{1,3})(?![\d.])/', $text, $matches) ? $matches[1] : null;
    }

    public function isPermanent(?string $statusCode): bool
    {
        return $statusCode !== null && str_starts_with($statusCode, '5');
    }

    /**
     * The message ID our token names, if the notice carries one (from a returned copy of our headers).
     */
    public function messageId(string $raw): ?int
    {
        if (! preg_match('/^'.preg_quote(BounceTokens::HEADER, '/').':\s*(\S+)/mi', $raw, $matches)) {
            return null;
        }

        return BounceTokens::messageIdFrom($matches[1]);
    }
}

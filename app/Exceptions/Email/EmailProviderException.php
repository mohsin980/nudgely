<?php

namespace App\Exceptions\Email;

use RuntimeException;

/**
 * A provider failure with a technical message for logs and a safe message for users.
 *
 * Neither message ever contains credentials or raw provider responses.
 */
class EmailProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const REJECTED = 'rejected';

    public const DOMAIN_NOT_FOUND = 'domain_not_found';

    public const DOMAIN_IN_USE = 'domain_in_use';

    public const IN_PROGRESS = 'in_progress';

    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    public function __construct(
        string $message,
        public readonly string $reason,
        private readonly string $userMessage,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $detail): self
    {
        return new self($detail, self::NOT_CONFIGURED,
            'Domain verification isn\'t available right now. Please contact support.');
    }

    public static function unavailable(string $detail): self
    {
        return new self($detail, self::UNAVAILABLE,
            'We couldn\'t reach our email provider right now. Please try again in a few minutes.');
    }

    public static function rejected(string $detail): self
    {
        return new self($detail, self::REJECTED,
            'We couldn\'t connect your domain. Please check that the domain is correct and try again.');
    }

    public static function domainNotFound(string $detail): self
    {
        return new self($detail, self::DOMAIN_NOT_FOUND,
            'This domain is no longer registered with our email provider. Click "Verify Domain" to set it up again.');
    }

    public static function domainInUse(string $domain): self
    {
        return new self("Domain [{$domain}] is registered by another organization.", self::DOMAIN_IN_USE,
            'This domain is already connected to another QuoteFlow account. Please contact support if you own this domain.');
    }

    public static function inProgress(string $domain): self
    {
        return new self("Domain [{$domain}] is locked by another request.", self::IN_PROGRESS,
            'Domain verification is already in progress. Please wait a moment and try again.');
    }

    public static function unexpectedResponse(string $detail): self
    {
        return new self($detail, self::UNEXPECTED_RESPONSE,
            'We couldn\'t connect your domain right now. Please try again.');
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }
}

<?php

namespace App\Services\Email\Data;

use InvalidArgumentException;

/**
 * A provider-independent outbound email.
 */
final readonly class OutboundEmail
{
    /**
     * @param  array<string, string>  $metadata  Small, non-sensitive identifiers (e.g. message ID) passed to the provider.
     */
    public function __construct(
        public int $organizationId,
        public string $fromEmail,
        public string $fromName,
        public string $toEmail,
        public ?string $toName,
        public string $subject,
        public ?string $textBody,
        public ?string $htmlBody,
        public ?string $replyTo = null,
        public array $metadata = [],
    ) {
        if (blank($textBody) && blank($htmlBody)) {
            throw new InvalidArgumentException('An outbound email needs a text or HTML body.');
        }
    }
}

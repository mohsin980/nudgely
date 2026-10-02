<?php

namespace App\Services\Email\Data;

use App\Enums\EmailProvider;
use Carbon\CarbonImmutable;

/**
 * A provider-independent inbound email, normalized from a provider webhook.
 *
 * Everything here comes from an untrusted sender: it never decides the
 * organization or conversation on its own.
 */
final readonly class InboundEmail
{
    /**
     * @param  list<string>  $recipients  Every lowercased recipient address (original recipient, To, Cc, Bcc).
     * @param  array<string, string>  $headers  Selected email headers, keyed by lowercased name.
     * @param  list<string>  $references
     * @param  list<array{name: string, content_type: string, size: int}>  $attachments  Metadata only; contents are never kept.
     * @param  array<string, string>  $rawMetadata  Small, non-sensitive provider details for troubleshooting.
     *
     * $receivedAt is the email's own Date header: sender-controlled, informational only.
     */
    public function __construct(
        public EmailProvider $provider,
        public string $providerMessageId,
        public ?string $messageId,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $toEmail,
        public array $recipients,
        public string $subject,
        public ?string $textBody,
        public ?string $htmlBody,
        public CarbonImmutable $receivedAt,
        public array $headers = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $attachments = [],
        public array $rawMetadata = [],
    ) {}
}

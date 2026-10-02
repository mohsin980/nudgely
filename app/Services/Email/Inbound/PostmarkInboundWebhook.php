<?php

namespace App\Services\Email\Inbound;

use App\Contracts\Email\InboundEmailProviderInterface;
use App\Enums\EmailProvider;
use App\Exceptions\Email\InvalidInboundEmailException;
use App\Services\Email\Data\InboundEmail;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Postmark inbound webhook (https://postmarkapp.com/developer/webhooks/inbound-webhook).
 *
 * Postmark does not sign inbound webhooks; it supports HTTP Basic auth credentials in the
 * webhook URL (https://user:secret@host/...), which is what we verify here.
 */
class PostmarkInboundWebhook implements InboundEmailProviderInterface
{
    /**
     * Headers kept on the normalized email (lowercased).
     */
    private const KEPT_HEADERS = ['message-id', 'in-reply-to', 'references', 'date', 'received-spf', 'authentication-results', 'x-spam-status'];

    /**
     * @param  array{inbound_webhook_username?: ?string, inbound_webhook_secret?: ?string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function authenticate(Request $request): bool
    {
        $username = (string) ($this->config['inbound_webhook_username'] ?? '');
        $secret = (string) ($this->config['inbound_webhook_secret'] ?? '');

        if ($username === '' || $secret === '') {
            Log::warning('Inbound email webhook rejected: webhook credentials are not configured.', ['provider' => 'postmark']);

            return false;
        }

        // Compare both parts in constant time, and always both, so timing reveals nothing.
        $userMatches = hash_equals($username, (string) $request->getUser());
        $secretMatches = hash_equals($secret, (string) $request->getPassword());

        return $userMatches && $secretMatches;
    }

    public function parse(array $payload): InboundEmail
    {
        $providerMessageId = $payload['MessageID'] ?? null;

        if (! is_string($providerMessageId) || $providerMessageId === '' || strlen($providerMessageId) > 255) {
            throw new InvalidInboundEmailException('Postmark inbound payload has no valid MessageID.');
        }

        $fromEmail = strtolower(trim((string) ($payload['FromFull']['Email'] ?? $payload['From'] ?? '')));

        if (! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidInboundEmailException('Postmark inbound payload has no valid sender.');
        }

        $recipients = $this->recipients($payload);

        if ($recipients === []) {
            throw new InvalidInboundEmailException('Postmark inbound payload has no recipients.');
        }

        $headers = $this->headers($payload['Headers'] ?? []);

        return new InboundEmail(
            provider: EmailProvider::Postmark,
            providerMessageId: $providerMessageId,
            messageId: $headers['message-id'] ?? null,
            fromEmail: $fromEmail,
            fromName: $this->string($payload['FromFull']['Name'] ?? $payload['FromName'] ?? null),
            toEmail: $this->firstEmail($payload['ToFull'] ?? []),
            recipients: $recipients,
            subject: (string) $this->string($payload['Subject'] ?? null),
            textBody: $this->string($payload['TextBody'] ?? null),
            htmlBody: $this->string($payload['HtmlBody'] ?? null),
            receivedAt: $this->date($payload['Date'] ?? null),
            headers: $headers,
            inReplyTo: $headers['in-reply-to'] ?? null,
            references: isset($headers['references']) ? array_values(array_filter(preg_split('/\s+/', $headers['references']) ?: [])) : [],
            attachments: $this->attachments($payload['Attachments'] ?? []),
            rawMetadata: array_filter([
                'message_stream' => $this->string($payload['MessageStream'] ?? null),
                'mailbox_hash' => $this->string($payload['MailboxHash'] ?? null),
            ]),
        );
    }

    public function sanitizePayload(array $payload): array
    {
        if (isset($payload['Attachments']) && is_array($payload['Attachments'])) {
            $payload['Attachments'] = array_map(
                fn ($attachment) => is_array($attachment) ? array_diff_key($attachment, ['Content' => true]) : [],
                $payload['Attachments'],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recipients(array $payload): array
    {
        $addresses = [$payload['OriginalRecipient'] ?? null];

        foreach (['ToFull', 'CcFull', 'BccFull'] as $field) {
            foreach (is_array($payload[$field] ?? null) ? $payload[$field] : [] as $recipient) {
                $addresses[] = is_array($recipient) ? ($recipient['Email'] ?? null) : null;
            }
        }

        $valid = array_filter(
            array_map(fn ($address) => is_string($address) ? strtolower(trim($address)) : '', $addresses),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        );

        return array_values(array_unique($valid));
    }

    private function firstEmail(mixed $recipients): ?string
    {
        $first = is_array($recipients) ? ($recipients[0]['Email'] ?? null) : null;

        return is_string($first) ? strtolower(trim($first)) : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(mixed $headers): array
    {
        $kept = [];

        foreach (is_array($headers) ? $headers : [] as $header) {
            $name = strtolower((string) ($header['Name'] ?? ''));

            if (in_array($name, self::KEPT_HEADERS, true) && ! isset($kept[$name]) && is_string($header['Value'] ?? null)) {
                $kept[$name] = mb_substr(trim($header['Value']), 0, 998);
            }
        }

        return $kept;
    }

    /**
     * @return list<array{name: string, content_type: string, size: int}>
     */
    private function attachments(mixed $attachments): array
    {
        $metadata = [];

        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (is_array($attachment)) {
                $metadata[] = [
                    'name' => mb_substr((string) ($attachment['Name'] ?? 'attachment'), 0, 255),
                    'content_type' => mb_substr((string) ($attachment['ContentType'] ?? 'application/octet-stream'), 0, 255),
                    'size' => (int) ($attachment['ContentLength'] ?? 0),
                ];
            }
        }

        return $metadata;
    }

    private function date(mixed $value): CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

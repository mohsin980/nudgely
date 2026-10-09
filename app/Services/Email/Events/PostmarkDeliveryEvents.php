<?php

namespace App\Services\Email\Events;

use App\Exceptions\Email\InvalidDeliveryEventException;
use App\Services\Email\Data\DeliveryEvent;
use Illuminate\Http\Request;

/**
 * Postmark's delivery webhooks: Delivery, Bounce and SpamComplaint records.
 */
class PostmarkDeliveryEvents
{
    /** Bounce types the provider retries on its own; they don't end the delivery attempt. */
    private const TEMPORARY_BOUNCE_TYPES = ['SoftBounce', 'Transient', 'DNSError'];

    private const RECORD_TYPES = ['Delivery', 'Bounce', 'SpamComplaint'];

    public function __construct(private readonly array $config) {}

    /**
     * Basic auth with its own credential, compared in constant time.
     */
    public function authenticate(Request $request): bool
    {
        $username = (string) ($this->config['events_webhook_username'] ?? '');
        $secret = (string) ($this->config['events_webhook_secret'] ?? '');

        if ($username === '' || $secret === '') {
            return false;
        }

        $userMatches = hash_equals($username, (string) $request->getUser());
        $secretMatches = hash_equals($secret, (string) $request->getPassword());

        return $userMatches && $secretMatches;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidDeliveryEventException
     */
    public function parse(array $payload): DeliveryEvent
    {
        $recordType = $payload['RecordType'] ?? null;
        $messageId = $payload['MessageID'] ?? null;
        $recipient = strtolower(trim((string) ($payload['Recipient'] ?? $payload['Email'] ?? '')));

        if (! in_array($recordType, self::RECORD_TYPES, true)) {
            throw new InvalidDeliveryEventException('Unsupported record type.');
        }

        if (! is_string($messageId) || preg_match('/^[A-Za-z0-9-]{8,100}$/', $messageId) !== 1) {
            throw new InvalidDeliveryEventException('Missing or invalid message ID.');
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidDeliveryEventException('Missing or invalid recipient.');
        }

        $type = match ($recordType) {
            'Delivery' => DeliveryEvent::DELIVERED,
            'SpamComplaint' => DeliveryEvent::COMPLAINED,
            'Bounce' => in_array((string) ($payload['Type'] ?? ''), self::TEMPORARY_BOUNCE_TYPES, true)
                ? DeliveryEvent::TEMPORARY
                : DeliveryEvent::BOUNCED,
        };

        // One report per record: Postmark's bounce and complaint IDs are unique; a delivery is unique per message.
        $externalId = $recordType.':'.(isset($payload['ID']) && is_scalar($payload['ID']) ? (string) $payload['ID'] : $messageId);

        return new DeliveryEvent($type, $messageId, $recipient, $externalId, $recordType);
    }
}

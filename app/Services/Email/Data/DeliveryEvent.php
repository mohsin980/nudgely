<?php

namespace App\Services\Email\Data;

/**
 * A provider's delivery report, normalized. Provider field names stop at the Postmark adapter.
 */
final readonly class DeliveryEvent
{
    public const DELIVERED = 'delivered';

    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    /** Soft bounces: the provider keeps retrying, so the message is neither delivered nor bounced yet. */
    public const TEMPORARY = 'temporary';

    public function __construct(
        public string $type,
        public string $providerMessageId,
        public string $recipient,
        public string $externalId,
        public string $providerRecordType,
    ) {}
}

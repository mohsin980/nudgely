<?php

namespace App\Services\AI\Data;

/**
 * The minimal, provider-independent context an AI classifier may see.
 *
 * Deliberately excludes email addresses, IDs, customer surnames and anything
 * not needed to understand the reply.
 */
final readonly class CustomerConversationContext
{
    /**
     * @param  list<array{from: 'business'|'customer', text: string}>  $previousMessages  Oldest first, already truncated.
     */
    public function __construct(
        public string $businessName,
        public ?string $customerFirstName,
        public ?string $topic,
        public string $latestReply,
        public array $previousMessages = [],
    ) {}
}

<?php

namespace App\Services\Conversations;

use DateTimeInterface;

/**
 * One event on a customer or conversation timeline.
 * kind: customer | business | system | automation.
 */
final readonly class TimelineEntry
{
    /**
     * @param  list<array{ok: bool, status: string, text: string}>  $details  Automation action results.
     */
    public function __construct(
        public DateTimeInterface $at,
        public string $kind,
        public string $title,
        public ?string $body = null,
        public array $details = [],
        public ?int $conversationId = null,
    ) {}
}

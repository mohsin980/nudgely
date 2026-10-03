<?php

namespace App\Services\Dashboard;

use App\Enums\AttentionPriority;
use App\Enums\CustomerReplyIntent;
use DateTimeInterface;

/**
 * One row of "Needs your attention": a conversation waiting for the business or a follow-up.
 */
final readonly class AttentionItem
{
    public function __construct(
        public string $kind,
        public AttentionPriority $priority,
        public int $customerId,
        public string $customerName,
        public string $reason,
        public ?string $excerpt,
        public ?CustomerReplyIntent $intent,
        public ?float $confidence,
        public DateTimeInterface $at,
        public string $url,
        public string $actionLabel,
    ) {}
}

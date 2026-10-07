<?php

namespace App\Billing;

use App\Enums\Billing\WebhookEventStatus;

/**
 * What handling a webhook event came to: processed (state re-read), or ignored with a reason.
 */
final readonly class WebhookOutcome
{
    private function __construct(
        public WebhookEventStatus $status,
        public ?int $organizationId = null,
        public ?string $reason = null,
    ) {}

    public static function processed(int $organizationId): self
    {
        return new self(WebhookEventStatus::Processed, $organizationId);
    }

    public static function ignored(string $reason, ?int $organizationId = null): self
    {
        return new self(WebhookEventStatus::Ignored, $organizationId, $reason);
    }
}

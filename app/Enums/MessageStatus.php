<?php

namespace App\Enums;

enum MessageStatus: string
{
    // Outbound
    case Queued = 'queued';

    // Claimed by a worker and handed to the provider; guards against sending twice.
    case Sending = 'sending';

    // Accepted by the provider. Later provider events move it on to delivered, bounced or complained.
    case Sent = 'sent';

    case Delivered = 'delivered';

    case Bounced = 'bounced';

    case Complained = 'complained';

    case Failed = 'failed';

    // Inbound
    case Received = 'received';

    // Arrived through a valid reply route but from an unexpected sender; not attached to the conversation.
    case NeedsReview = 'needs_review';

    /**
     * The only status changes the application makes. Provider events and the send job must go
     * through this, so a late or repeated event cannot move a message backwards (e.g. failed → delivered).
     */
    public function canTransitionTo(self $next, bool $providerEvidence = false): bool
    {
        // Documented reconciliation: a send that timed out was recorded as failed ("outcome unknown"), but
        // the provider later reports it delivered or bounced. Provider events are the only evidence that
        // can override that; nothing else may move a failed message.
        if ($this === self::Failed) {
            return $providerEvidence && in_array($next, [self::Delivered, self::Bounced, self::Complained], true);
        }

        return in_array($next, match ($this) {
            self::Queued => [self::Sending, self::Failed],
            self::Sending => [self::Sent, self::Queued, self::Failed],
            self::Sent => [self::Delivered, self::Bounced, self::Complained],
            self::Delivered => [self::Complained],
            default => [],
        }, true);
    }

    /**
     * Outbound email that went to the provider and counts against plan limits. Failed ones never did.
     */
    public function countsAsSent(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::Sent, self::Delivered, self::Bounced, self::Complained], true);
    }
}

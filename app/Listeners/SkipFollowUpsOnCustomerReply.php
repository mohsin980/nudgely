<?php

namespace App\Listeners;

use App\Events\CustomerReplyReceived;
use App\Models\Message;
use App\Services\FollowUps\FollowUpService;

/**
 * A customer reply makes the conversation's open automated follow-ups unnecessary:
 * they are skipped with reason "customer_replied". Manual reminders are left to the person.
 */
class SkipFollowUpsOnCustomerReply
{
    public function __construct(private readonly FollowUpService $followUps) {}

    public function handle(CustomerReplyReceived $event): void
    {
        $repliedAt = Message::query()
            ->where('organization_id', $event->organizationId)
            ->whereKey($event->messageId)
            ->value('received_at');

        $this->followUps->skipForCustomerReply($event->organizationId, $event->conversationId, $repliedAt ?? now());
    }
}

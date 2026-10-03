<?php

namespace App\Listeners;

use App\Events\CustomerReplyReceived;
use App\Services\Automation\FollowUpProcessor;

/**
 * A customer reply makes pending follow-ups for that conversation unnecessary.
 */
class SkipFollowUpsOnCustomerReply
{
    public function __construct(private readonly FollowUpProcessor $followUps) {}

    public function handle(CustomerReplyReceived $event): void
    {
        $this->followUps->skipForReply($event->organizationId, $event->conversationId);
    }
}

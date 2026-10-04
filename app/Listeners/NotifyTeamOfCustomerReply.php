<?php

namespace App\Listeners;

use App\Events\CustomerReplyReceived;
use App\Services\Team\ActivityNotifications;

class NotifyTeamOfCustomerReply
{
    public function __construct(private readonly ActivityNotifications $notifications) {}

    public function handle(CustomerReplyReceived $event): void
    {
        $this->notifications->customerReplied($event->organizationId, $event->conversationId, $event->messageId);
    }
}

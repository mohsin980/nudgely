<?php

namespace App\Listeners;

use App\Events\CustomerReplyReceived;
use App\Services\Customers\CustomerActivityService;

/**
 * A reply from a customer marked inactive makes them active again straight away.
 */
class ReactivateCustomerOnReply
{
    public function __construct(private readonly CustomerActivityService $activity) {}

    public function handle(CustomerReplyReceived $event): void
    {
        $this->activity->reactivateOnReply($event->organizationId, $event->customerId, $event->conversationId);
    }
}

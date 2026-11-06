<?php

namespace App\Listeners;

use App\Enums\LeadStage;
use App\Events\CustomerReplyReceived;
use App\Events\EstimateAccepted;
use App\Events\EstimateDeclined;
use App\Events\EstimateSent;
use App\Services\Leads\LeadStageService;

/**
 * Moves leads forward as things happen: a reply → Contacted, an estimate sent → Quoted,
 * accepted → Won, declined → Lost. (A first email from the business is handled where it is sent.)
 */
class UpdateLeadStage
{
    public function __construct(private readonly LeadStageService $stages) {}

    public function handleReply(CustomerReplyReceived $event): void
    {
        $this->stages->advance($event->organizationId, $event->customerId, LeadStage::Contacted, 'customer_replied', $event->conversationId);
    }

    public function handleEstimateSent(EstimateSent $event): void
    {
        $this->stages->advance($event->organizationId, $event->customerId, LeadStage::Quoted, 'estimate_sent', $event->conversationId);
    }

    public function handleEstimateAccepted(EstimateAccepted $event): void
    {
        $this->stages->advance($event->organizationId, $event->customerId, LeadStage::Won, 'estimate_accepted', $event->conversationId);
    }

    public function handleEstimateDeclined(EstimateDeclined $event): void
    {
        $this->stages->estimateDeclined($event->organizationId, $event->customerId, $event->estimateId, $event->conversationId);
    }
}

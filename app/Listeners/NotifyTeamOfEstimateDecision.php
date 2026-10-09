<?php

namespace App\Listeners;

use App\Events\EstimateAccepted;
use App\Events\EstimateDeclined;
use App\Services\Team\ActivityNotifications;

class NotifyTeamOfEstimateDecision
{
    public function __construct(private readonly ActivityNotifications $notifications) {}

    public function handle(EstimateAccepted|EstimateDeclined $event): void
    {
        $this->notifications->estimateDecided($event->organizationId, $event->estimateId, $event instanceof EstimateAccepted);
    }
}

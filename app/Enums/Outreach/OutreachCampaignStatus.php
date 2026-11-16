<?php

namespace App\Enums\Outreach;

enum OutreachCampaignStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
}

<?php

namespace App\Services\Automation\Actions;

use App\Enums\FollowUpStatus;

class CancelFollowUpAction extends CloseFollowUpsAction
{
    protected function status(): FollowUpStatus
    {
        return FollowUpStatus::Cancelled;
    }
}

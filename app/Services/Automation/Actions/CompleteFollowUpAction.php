<?php

namespace App\Services\Automation\Actions;

use App\Enums\FollowUpStatus;

class CompleteFollowUpAction extends CloseFollowUpsAction
{
    protected function status(): FollowUpStatus
    {
        return FollowUpStatus::Completed;
    }
}

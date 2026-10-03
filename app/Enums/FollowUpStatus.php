<?php

namespace App\Enums;

enum FollowUpStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Scheduled',
            self::Processing => 'Processing',
            self::Completed => 'Done',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }
}

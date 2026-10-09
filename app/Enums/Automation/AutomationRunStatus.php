<?php

namespace App\Enums\Automation;

enum AutomationRunStatus: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Running => 'Running',
            self::Completed => 'Success',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * Still in progress: waiting for its WAIT to end, or running its actions.
     */
    public function isPending(): bool
    {
        return $this === self::Waiting || $this === self::Running;
    }
}

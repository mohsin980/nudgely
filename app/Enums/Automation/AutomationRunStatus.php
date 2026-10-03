<?php

namespace App\Enums\Automation;

enum AutomationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

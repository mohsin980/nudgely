<?php

namespace App\Enums\Automation;

enum AutomationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}

<?php

namespace App\Enums\Outreach;

enum OutreachMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}

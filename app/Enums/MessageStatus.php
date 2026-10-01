<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    // Claimed by a worker and handed to the provider; guards against sending twice.
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
}

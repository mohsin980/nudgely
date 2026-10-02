<?php

namespace App\Enums;

enum MessageStatus: string
{
    // Outbound
    case Queued = 'queued';
    // Claimed by a worker and handed to the provider; guards against sending twice.
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    // Inbound
    case Received = 'received';
    // Arrived through a valid reply route but from an unexpected sender; not attached to the conversation.
    case NeedsReview = 'needs_review';
}

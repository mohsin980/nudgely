<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

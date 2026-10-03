<?php

namespace App\Enums;

enum ConversationCloseReason: string
{
    case Completed = 'completed';
    case NotInterested = 'not_interested';
    case Duplicate = 'duplicate';
    case NoResponse = 'no_response';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::NotInterested => 'Not interested',
            self::Duplicate => 'Duplicate',
            self::NoResponse => 'No response',
            self::Other => 'Other',
        };
    }
}

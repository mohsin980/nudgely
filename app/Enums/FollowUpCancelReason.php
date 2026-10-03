<?php

namespace App\Enums;

/**
 * Why a person cancelled a follow-up. Stored as follow_ups.cancelled_reason.
 */
enum FollowUpCancelReason: string
{
    case CustomerReplied = 'customer_replied';
    case NotInterested = 'not_interested';
    case Duplicate = 'duplicate';
    case ManuallyCancelled = 'manually_cancelled';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReplied => 'Customer replied',
            self::NotInterested => 'Customer no longer interested',
            self::Duplicate => 'Duplicate',
            self::ManuallyCancelled => 'No longer needed',
            self::Other => 'Other',
        };
    }
}

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
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReplied => 'Customer replied',
            self::NotInterested => 'Customer no longer interested',
            self::Duplicate => 'Duplicate',
            self::ManuallyCancelled => 'No longer needed',
            self::Other => 'Other',
            self::Automation => 'Cancelled by an automation',
        };
    }

    /**
     * Reasons a person can choose (automations use their own).
     *
     * @return list<self>
     */
    public static function forPeople(): array
    {
        return array_values(array_filter(self::cases(), fn (self $reason) => $reason !== self::Automation));
    }
}

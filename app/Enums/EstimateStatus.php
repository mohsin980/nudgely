<?php

namespace App\Enums;

/**
 * Where an estimate is in its life. Values are stored in the database: never rename them.
 */
enum EstimateStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Sent or viewed: waiting for the customer's decision.
     */
    public function isAwaitingCustomer(): bool
    {
        return $this === self::Sent || $this === self::Viewed;
    }

    /**
     * Only drafts can be changed. A sent estimate is revised instead (a new version).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * A sent estimate can be revised unless the customer already accepted it.
     */
    public function canBeRevised(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::Declined, self::Expired], true);
    }

    /**
     * @return list<self>
     */
    public static function awaitingCustomer(): array
    {
        return [self::Sent, self::Viewed];
    }

    /**
     * Tailwind classes for the status badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            self::Sent => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            self::Viewed => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            self::Accepted => 'bg-green-50 text-green-700 ring-green-600/20',
            self::Declined => 'bg-red-50 text-red-700 ring-red-600/20',
            self::Expired => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Cancelled => 'bg-gray-100 text-gray-500 ring-gray-500/20',
        };
    }
}

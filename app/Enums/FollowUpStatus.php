<?php

namespace App\Enums;

/**
 * pending → due → completed, with cancel/skip/fail as the other ways out.
 * Completed, cancelled, skipped and failed are final.
 */
enum FollowUpStatus: string
{
    case Pending = 'pending';
    case Due = 'due';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Due => 'Due',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }

    /**
     * Still waiting to be dealt with.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Due;
    }

    /**
     * The only allowed status changes. Rescheduling moves a due follow-up back to pending.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Due, self::Completed, self::Cancelled, self::Skipped],
            self::Due => [self::Pending, self::Completed, self::Cancelled, self::Skipped, self::Failed],
            self::Completed, self::Cancelled, self::Skipped, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Statuses a follow-up may be in when moving to $next.
     *
     * @return list<self>
     */
    public static function sourcesFor(self $next): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->canTransitionTo($next)));
    }
}

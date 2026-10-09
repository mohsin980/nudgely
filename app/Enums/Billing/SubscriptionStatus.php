<?php

namespace App\Enums\Billing;

/**
 * Subscription states (named after the common payment-provider lifecycle).
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Incomplete = 'incomplete';
    case Unpaid = 'unpaid';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PastDue => 'Past due',
            default => ucfirst($this->value),
        };
    }

    /**
     * The plan's limits and features apply. Past due keeps access while the provider retries payment.
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /**
     * Finished: a new subscription may be started. Every other status is "current".
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }

    /**
     * @return list<string>
     */
    public static function currentValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => ! $s->isTerminal())));
    }
}

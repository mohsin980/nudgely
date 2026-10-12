<?php

namespace App\Enums\Billing;

/**
 * Where an organization stands against one plan limit. "At limit" and "Over" match what the backend enforces:
 * creating one more is refused when usage + 1 would exceed the limit, i.e. at or above the limit.
 */
enum QuotaState: string
{
    case Unlimited = 'unlimited';
    case Ok = 'ok';
    case Near = 'near';
    case AtLimit = 'at_limit';
    case Over = 'over';

    public function label(): string
    {
        return match ($this) {
            self::Unlimited => 'Unlimited',
            self::Ok => 'OK',
            self::Near => 'Nearing limit',
            self::AtLimit => 'At limit',
            self::Over => 'Over limit',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unlimited, self::Ok => 'success',
            self::Near => 'warning',
            self::AtLimit, self::Over => 'danger',
        };
    }

    /** Higher is worse; used to pick an organization's overall state. */
    public function severity(): int
    {
        return match ($this) {
            self::Unlimited => 0,
            self::Ok => 1,
            self::Near => 2,
            self::AtLimit => 3,
            self::Over => 4,
        };
    }

    public static function for(int $used, ?int $limit, int $warnPercent): self
    {
        if ($limit === null) {
            return self::Unlimited;
        }

        return match (true) {
            $used > $limit => self::Over,
            $used >= $limit => self::AtLimit,
            $used * 100 >= $limit * $warnPercent => self::Near,
            default => self::Ok,
        };
    }
}

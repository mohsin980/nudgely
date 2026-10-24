<?php

namespace App\Enums\Billing;

/**
 * How far a locally stored subscription can be trusted against the billing provider, which is the source of truth.
 */
enum DataFreshness: string
{
    /** Confirmed by the provider recently. */
    case Confirmed = 'confirmed';

    /** Confirmed once, but not for longer than expected (webhooks and the hourly sync should keep it fresh). */
    case Stale = 'stale';

    /** The provider has never confirmed this record (created before sync times were recorded, or never synced). */
    case Unconfirmed = 'unconfirmed';

    /** Created by the manual provider: there is no external provider to confirm it. */
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed',
            self::Stale => 'Stale',
            self::Unconfirmed => 'Not yet confirmed',
            self::Local => 'Local only',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Stale => 'warning',
            self::Unconfirmed => 'danger',
            self::Local => 'gray',
        };
    }
}

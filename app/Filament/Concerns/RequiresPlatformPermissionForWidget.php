<?php

namespace App\Filament\Concerns;

/**
 * RequiresPlatformPermission for dashboard widgets, which ask canView() rather than canAccess().
 */
trait RequiresPlatformPermissionForWidget
{
    use RequiresPlatformPermission;

    public static function canView(): bool
    {
        return static::canAccess();
    }
}

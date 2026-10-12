<?php

namespace App\Filament\Concerns;

use App\Enums\Platform\PlatformPermission;
use App\Support\Admin\AdminAccess;

/**
 * Fail-closed access for admin pages, resources and widgets: nothing is reachable (menu, URL, Livewire call)
 * unless the class names the platform permission it needs and the signed-in admin holds it.
 * Visibility checks run on every render, so they are not written to the audit log (panel entry is).
 */
trait RequiresPlatformPermission
{
    abstract protected static function requiredPermission(): PlatformPermission;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth()->user(), static::requiredPermission(), audit: false);
    }

    public static function canView(): bool
    {
        return static::canAccess();
    }
}

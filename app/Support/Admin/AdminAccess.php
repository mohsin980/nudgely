<?php

namespace App\Support\Admin;

use App\Enums\Platform\PlatformPermission;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The one place that decides who may use the Super Admin panel.
 *
 * Every panel page, resource, widget and the panel gate itself ask this class, so the rule is written once:
 * an active account, with a platform_admins row, whose role holds the permission. Anything else is denied.
 */
final class AdminAccess
{
    /** May this user enter the panel at all? */
    public static function canEnterPanel(?User $user): bool
    {
        return self::allows($user, PlatformPermission::AccessAdminPanel, audit: false);
    }

    /** May this user do what the permission describes? Guests and customers never can. */
    public static function allows(?User $user, PlatformPermission $permission, bool $audit = true): bool
    {
        $allowed = $user !== null
            && $user->hasPlatformPermission(PlatformPermission::AccessAdminPanel)
            && $user->hasPlatformPermission($permission);

        if (! $allowed && $audit && $user !== null) {
            self::logDenied($user, $permission->value);
        }

        return $allowed;
    }

    /** Records a refused attempt. Only IDs are logged: no email, no request data. */
    public static function logDenied(User $user, string $what): void
    {
        Log::channel(config('admin.audit_channel'))->warning('Admin access denied', [
            'user_id' => $user->getKey(),
            'permission' => $what,
            'path' => request()->path(),
            'ip' => request()->ip(),
        ]);
    }
}

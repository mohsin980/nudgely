<?php

namespace App\Services\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Lets a super admin give or take the outreach permissions from any active platform administrator. Only outreach
 * permissions can be handed out here; platform roles and everything else stay as they are.
 */
class OutreachAccess
{
    public const GRANTABLE = [
        PlatformPermission::ViewOutreach,
        PlatformPermission::ManageOutreachCategories,
        PlatformPermission::ManageOutreachContacts,
        PlatformPermission::ManageOutreachTemplates,
        PlatformPermission::ApproveOutreachTemplates,
        PlatformPermission::SendOutreach,
    ];

    public function set(User $grantor, User $target, PlatformPermission $permission, bool $granted): void
    {
        if (! AdminAccess::allows($grantor, PlatformPermission::ManageOutreachAccess)) {
            throw new AuthorizationException('Only a super admin can change outreach access.');
        }

        if (! in_array($permission, self::GRANTABLE, true)) {
            throw ValidationException::withMessages(['permission' => 'That permission is not an outreach permission.']);
        }

        if (! $target->isActivePlatformAdmin()) {
            throw ValidationException::withMessages(['user' => 'Only active platform administrators can be given outreach access.']);
        }

        $granted
            ? $target->givePermissionTo($permission->value)
            : $target->revokePermissionTo($permission->value);

        Log::channel(config('admin.audit_channel'))->info('Outreach access changed', [
            'grantor_id' => $grantor->id,
            'target_id' => $target->id,
            'permission' => $permission->value,
            'granted' => $granted,
        ]);
    }
}

<?php

namespace App\Policies;

use App\Enums\Platform\PlatformPermission;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * Reading the audit trail needs view_audit_logs. Nobody, whatever their role, can create, edit or delete an
 * audit event through the application: events are written by the platform's own code and expire by retention only.
 */
class PlatformAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return AdminAccess::allows($user, PlatformPermission::ViewAuditLogs, audit: false);
    }

    public function view(User $user, PlatformAuditLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PlatformAuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, PlatformAuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

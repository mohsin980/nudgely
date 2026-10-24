<?php

namespace App\Enums\Platform;

/**
 * Platform-wide permissions: what an administrator of the whole SaaS may do (the /admin panel).
 *
 * These are separate from App\Enums\Team\Permission, which is what a business's own owner, manager and staff may
 * do inside that one business. Each case is a Gate of the same name (see AppServiceProvider) and a row in the
 * permissions table (see PlatformRolesAndPermissionsSeeder).
 */
enum PlatformPermission: string
{
    case AccessAdminPanel = 'access_admin_panel';
    case ViewDashboard = 'view_dashboard';

    case ViewOrganizations = 'view_organizations';
    case ManageOrganizations = 'manage_organizations';
    case SuspendOrganizations = 'suspend_organizations';

    case ViewUsers = 'view_users';
    case ManageUsers = 'manage_users';

    case ViewPlans = 'view_plans';
    case ManagePlans = 'manage_plans';

    case ViewSubscriptions = 'view_subscriptions';
    case ManageSubscriptions = 'manage_subscriptions';

    case ViewPayments = 'view_payments';
    case ManagePayments = 'manage_payments';

    case ViewUsage = 'view_usage';
    case ViewAuditLogs = 'view_audit_logs';

    case ManageRoles = 'manage_roles';
    case ManageSystemSettings = 'manage_system_settings';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}

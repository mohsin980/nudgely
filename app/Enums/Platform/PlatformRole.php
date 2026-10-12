<?php

namespace App\Enums\Platform;

/**
 * The built-in platform roles and exactly what each one may do. This is the single place to change that.
 *
 * Least privilege: only super_admin holds every permission. Nobody gets a permission "because they are an
 * administrator". A business's own owner/manager/staff roles are a different system (App\Enums\OrganizationRole).
 */
enum PlatformRole: string
{
    /** The auth guard the roles and permissions belong to: the same one the Super Admin panel signs in with. */
    public const GUARD = 'web';

    case SuperAdmin = 'super_admin';
    case SupportAdmin = 'support_admin';
    case BillingAdmin = 'billing_admin';
    case BusinessOwner = 'business_owner';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::SupportAdmin => 'Support Admin',
            self::BillingAdmin => 'Billing Admin',
            self::BusinessOwner => 'Business Owner',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Everything on the platform, including plans, roles and system settings.',
            self::SupportAdmin => 'Look up businesses, users, plans, subscriptions, usage and the audit log to help customers. Changes nothing, and sees no payment details.',
            self::BillingAdmin => 'See plans, subscriptions, payments and usage. Changes nothing unless a billing action is granted to the person separately.',
            self::BusinessOwner => 'A customer. Holds no platform permissions; what an owner can do is limited to their own business (see OrganizationRole).',
        };
    }

    /**
     * Whether this role belongs to the people who run the platform (as opposed to customers).
     */
    public function isPlatformStaff(): bool
    {
        return $this !== self::BusinessOwner;
    }

    /**
     * @return list<PlatformPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => PlatformPermission::cases(),

            self::SupportAdmin => [
                PlatformPermission::AccessAdminPanel,
                PlatformPermission::ViewDashboard,
                PlatformPermission::ViewOrganizations,
                PlatformPermission::ViewUsers,
                PlatformPermission::ViewPlans,
                PlatformPermission::ViewSubscriptions,
                PlatformPermission::ViewUsage,
                PlatformPermission::ViewAuditLogs,
            ],

            self::BillingAdmin => [
                PlatformPermission::AccessAdminPanel,
                PlatformPermission::ViewDashboard,
                PlatformPermission::ViewOrganizations,
                PlatformPermission::ViewPlans,
                PlatformPermission::ViewSubscriptions,
                PlatformPermission::ViewPayments,
                PlatformPermission::ViewUsage,
            ],

            // Customers never receive platform permissions.
            self::BusinessOwner => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    /**
     * Roles that may be given to a person who runs the platform.
     *
     * @return list<self>
     */
    public static function staffRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role) => $role->isPlatformStaff()));
    }
}

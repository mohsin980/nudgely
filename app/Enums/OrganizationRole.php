<?php

namespace App\Enums;

use App\Enums\Team\Permission;

/**
 * The three built-in roles. What each may do is defined once, here (see Permission).
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including team, email, business settings and ownership.',
            self::Manager => 'Day-to-day work plus automations and business defaults. No team, email or ownership changes.',
            self::Staff => 'Customers, conversations, estimates, follow-ups and tasks. Can view automations.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => [
                Permission::ManageBusinessDefaults,
                Permission::ManageAutomations,
                Permission::ViewAutomations,
                Permission::ReclassifyReplies,
            ],
            self::Staff => [
                Permission::ViewAutomations,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Roles that can be given through an invitation or a role change (ownership only moves by transfer).
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Manager, self::Staff];
    }
}

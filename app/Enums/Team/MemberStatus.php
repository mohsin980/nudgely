<?php

namespace App\Enums\Team;

/**
 * Users are active, suspended or removed; "invited" describes an open invitation.
 * Suspended and removed people keep their history but can't sign in or act.
 */
enum MemberStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

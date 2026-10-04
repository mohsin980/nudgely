<?php

namespace App\Enums\Automation;

enum AutomationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }

    /**
     * An archived automation must be restored (as a draft) before it can be activated.
     */
    public function canActivate(): bool
    {
        return $this !== self::Archived && $this !== self::Active;
    }
}

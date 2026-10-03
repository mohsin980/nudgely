<?php

namespace App\Enums;

enum FollowUpType: string
{
    case Manual = 'manual';
    case Automated = 'automated';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automated => 'Automated',
        };
    }
}

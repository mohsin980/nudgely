<?php

namespace App\Enums;

/**
 * Inactive customers are kept (with their history); they are simply filtered out of day-to-day lists.
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

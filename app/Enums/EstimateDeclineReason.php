<?php

namespace App\Enums;

/**
 * Why a customer declined an estimate (optional, chosen on the customer page).
 */
enum EstimateDeclineReason: string
{
    case TooExpensive = 'too_expensive';
    case WentWithAnotherCompany = 'went_with_another_company';
    case NoLongerNeeded = 'no_longer_needed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TooExpensive => 'Too expensive',
            self::WentWithAnotherCompany => 'Went with another company',
            self::NoLongerNeeded => 'No longer needed',
            self::Other => 'Other',
        };
    }
}

<?php

namespace App\Enums\Billing;

/**
 * What plans limit. Monthly limits reset each calendar month (organization timezone);
 * the others count what exists now.
 */
enum LimitKey: string
{
    case Customers = 'customers';
    case Automations = 'automations';
    case TeamMembers = 'team_members';
    case OutboundEmails = 'outbound_emails';
    case Estimates = 'estimates';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Customers',
            self::Automations => 'Automations',
            self::TeamMembers => 'Team members',
            self::OutboundEmails => 'Outbound emails / month',
            self::Estimates => 'Estimates / month',
        };
    }

    public function isMonthly(): bool
    {
        return in_array($this, [self::OutboundEmails, self::Estimates], true);
    }
}

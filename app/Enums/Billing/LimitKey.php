<?php

namespace App\Enums\Billing;

/**
 * What plans limit. Monthly limits reset with the billing period (calendar month without a subscription);
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
            self::OutboundEmails => 'Outbound emails / period',
            self::Estimates => 'Estimates / period',
        };
    }

    /**
     * "customer" in "You've reached your customer limit."
     */
    public function shortName(): string
    {
        return match ($this) {
            self::Customers => 'customer',
            self::Automations => 'automation',
            self::TeamMembers => 'team member',
            self::OutboundEmails => 'email',
            self::Estimates => 'estimate',
        };
    }

    /**
     * How the limit reads in a sentence: "allows up to 100 customers".
     */
    public function noun(): string
    {
        return match ($this) {
            self::Customers => 'customers',
            self::Automations => 'active automations',
            self::TeamMembers => 'team members',
            self::OutboundEmails => 'outbound emails a month',
            self::Estimates => 'new estimates a month',
        };
    }

    public function isMonthly(): bool
    {
        return in_array($this, [self::OutboundEmails, self::Estimates], true);
    }
}

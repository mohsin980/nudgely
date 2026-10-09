<?php

namespace App\Enums\Team;

/**
 * Organization-level abilities beyond the everyday work every active member does
 * (customers, conversations, estimates, follow-ups, tasks). Each case is a Gate of the same name.
 */
enum Permission: string
{
    case ManageBusinessProfile = 'manage-business-profile';
    case ManageBusinessDefaults = 'manage-business-defaults';
    case ManageTeam = 'manage-team';
    case ManageEmail = 'manage-email';
    case ManageAutomations = 'manage-automations';
    case ViewAutomations = 'view-automations';
    case ReclassifyReplies = 'reclassify-replies';
    case TransferOwnership = 'transfer-ownership';
    case ManageBilling = 'manage-billing';

    public function label(): string
    {
        return match ($this) {
            self::ManageBusinessProfile => 'Business profile, preferences and business hours',
            self::ManageBusinessDefaults => 'Estimate, follow-up, automation and notification defaults',
            self::ManageTeam => 'Invite, change roles, suspend and remove team members',
            self::ManageEmail => 'Email sender, domain verification and automatic email safety',
            self::ManageAutomations => 'Create, edit, activate and pause automations',
            self::ViewAutomations => 'View automations and their execution logs',
            self::ReclassifyReplies => 'Re-run AI classification of customer replies',
            self::TransferOwnership => 'Transfer ownership of the business',
            self::ManageBilling => 'View and change the subscription plan',
        };
    }
}

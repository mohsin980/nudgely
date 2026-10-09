<?php

namespace App\Support\Settings;

use App\Enums\Team\Permission;
use App\Models\User;

/**
 * The settings menu, filtered to the pages the person may open (the same permissions the
 * routes enforce, so hidden links are never the only protection).
 */
final class SettingsNavigation
{
    /**
     * @return list<array{label: string, items: list<array{label: string, route: string}>}>
     */
    public static function for(User $user): array
    {
        $groups = [
            'General' => [
                ['Business Profile', 'settings.business', Permission::ManageBusinessProfile],
                ['Business Preferences', 'settings.preferences', Permission::ManageBusinessProfile],
            ],
            'Communication' => [
                ['Email', 'settings.email', Permission::ManageEmail],
                ['Notifications', 'settings.notifications', null],
            ],
            'Team' => [
                ['Team Members', 'settings.team', Permission::ManageTeam],
                ['Roles & Permissions', 'settings.roles', null],
            ],
            'Estimates & Follow-ups' => [
                ['Estimate Defaults', 'settings.estimates', Permission::ManageBusinessDefaults],
                ['Follow-up Defaults', 'settings.follow-ups', Permission::ManageBusinessDefaults],
            ],
            'Automation' => [
                ['Automation Defaults', 'settings.automation', Permission::ManageBusinessDefaults],
            ],
            'Account' => [
                ['Account & Security', 'settings.security', null],
                ['Billing', 'settings.billing', Permission::ManageBilling],
            ],
        ];

        $menu = [];

        foreach ($groups as $label => $items) {
            $allowed = array_values(array_filter($items, fn (array $item) => $user->isActiveMember() && ($item[2] === null || $user->hasPermission($item[2]))));

            if ($allowed !== []) {
                $menu[] = ['label' => $label, 'items' => array_map(fn (array $item) => ['label' => $item[0], 'route' => $item[1]], $allowed)];
            }
        }

        return $menu;
    }

    /**
     * Where /settings takes the person: their first available page.
     */
    public static function home(User $user): string
    {
        return self::for($user)[0]['items'][0]['route'] ?? 'settings.security';
    }
}

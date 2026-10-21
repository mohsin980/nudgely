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
     * @return list<array{label: string, icon: string, items: list<array{label: string, route: string}>}>
     */
    public static function for(User $user): array
    {
        // Four plain groups. Labels are what a business owner would call these things.
        $groups = [
            'Business' => ['icon' => 'building', 'items' => [
                ['Business Profile', 'settings.business', Permission::ManageBusinessProfile],
                ['Business Preferences', 'settings.preferences', Permission::ManageBusinessProfile],
                ['Team Members', 'settings.team', Permission::ManageTeam],
                ['Roles & Permissions', 'settings.roles', Permission::ManageTeam],
            ]],
            'Customers & messages' => ['icon' => 'chat', 'items' => [
                ['Email', 'settings.email', Permission::ManageEmail],
                ['Notifications', 'settings.notifications', null],
            ]],
            'Quotes & reminders' => ['icon' => 'document', 'items' => [
                ['Quote defaults', 'settings.estimates', Permission::ManageBusinessDefaults],
                ['Reminder defaults', 'settings.follow-ups', Permission::ManageBusinessDefaults],
                ['Automation defaults', 'settings.automation', Permission::ManageBusinessDefaults],
            ]],
            'Your account' => ['icon' => 'cog', 'items' => [
                ['Account & Security', 'settings.security', null],
                ['Billing', 'settings.billing', Permission::ManageBilling],
            ]],
        ];

        $menu = [];

        foreach ($groups as $label => ['icon' => $icon, 'items' => $items]) {
            $allowed = array_values(array_filter($items, fn (array $item) => $user->isActiveMember() && ($item[2] === null || $user->hasPermission($item[2]))));

            if ($allowed !== []) {
                $menu[] = ['label' => $label, 'icon' => $icon, 'items' => array_map(fn (array $item) => ['label' => $item[0], 'route' => $item[1]], $allowed)];
            }
        }

        return $menu;
    }

    /**
     * Where /settings takes the person: the first page they can change. Roles & Permissions is
     * read-only for most people, so it is never the landing page.
     */
    public static function home(User $user): string
    {
        $routes = collect(self::for($user))->pluck('items')->flatten(1)->pluck('route');

        return $routes->first(fn (string $route) => $route !== 'settings.roles') ?? 'settings.security';
    }
}

<?php

namespace App\Livewire\Settings;

use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What each role may do, read straight from OrganizationRole::permissions() (so the page can't
 * drift from what is enforced). Everyone on the team can read it; roles are fixed.
 */
#[Layout('components.layouts.app')]
#[Title('Roles & Permissions')]
class RolesPermissions extends Component
{
    use SettingsPage;

    /** Work every active member does, whatever their role. */
    public const EVERYONE = ['Customers', 'Conversations and replies', 'Estimates', 'Follow-ups', 'Tasks', 'Your own notifications, password and sessions'];

    public function mount(): void
    {
        $this->authorize('access-organization');
    }

    public function render()
    {
        $rows = array_map(fn (string $label) => ['label' => $label, 'roles' => array_fill_keys(array_map(fn ($r) => $r->value, OrganizationRole::cases()), true)], self::EVERYONE);

        foreach (Permission::cases() as $permission) {
            $rows[] = ['label' => $permission->label(), 'roles' => collect(OrganizationRole::cases())->mapWithKeys(fn (OrganizationRole $r) => [$r->value => $r->can($permission)])->all()];
        }

        return view('livewire.settings.roles-permissions', [
            'roles' => OrganizationRole::cases(),
            'rows' => $rows,
            'counts' => User::query()->where('organization_id', $this->organization()->id)->where('status', MemberStatus::Active)
                ->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
        ]);
    }
}

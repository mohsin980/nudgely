<?php

namespace App\Livewire\Settings;

use App\Enums\Team\NotificationChannel;
use App\Enums\Team\NotificationType;
use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Team\NotificationPreferences;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Everyone: which notifications they get, in the app and by email. Owners and managers also
 * set the business's defaults, which apply to anyone who hasn't chosen.
 */
#[Layout('components.layouts.app')]
#[Title('Notifications')]
class NotificationSettings extends Component
{
    use SettingsPage;

    /** @var array<string, array<string, bool>> */
    public array $mine = [];

    /** @var array<string, array<string, bool>> */
    public array $defaults = [];

    public function mount(NotificationPreferences $preferences): void
    {
        $this->authorize('access-organization');
        $this->mine = $preferences->effective($this->user());
        $this->defaults = $preferences->organizationDefaults($this->organization());
    }

    public function save(NotificationPreferences $preferences): void
    {
        $this->authorize('access-organization');
        $preferences->saveForUser($this->user(), $this->mine);
        $this->mine = $preferences->effective($this->user());
        $this->saved('Your notification settings were saved.');
    }

    public function useDefaults(NotificationPreferences $preferences): void
    {
        $this->authorize('access-organization');
        $preferences->resetForUser($this->user());
        $this->mine = $preferences->effective($this->user());
        $this->saved('You now use the business defaults.');
    }

    public function saveDefaults(NotificationPreferences $preferences): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);
        $preferences->saveOrganizationDefaults($this->user(), $this->defaults);
        $this->mine = $preferences->effective($this->user());
        $this->saved('Business notification defaults saved.');
    }

    public function render(NotificationPreferences $preferences)
    {
        $organization = $this->organization();

        return view('livewire.settings.notification-settings', [
            'types' => NotificationType::cases(),
            'channels' => NotificationChannel::cases(),
            'usingDefaults' => ! $preferences->hasOverrides($this->user()),
            'canSendEmail' => $organization->emailConnections()->where('is_default', true)->where('verification_status', 'verified')->exists(),
            'organization' => $organization,
            'history' => $this->user()->hasPermission(Permission::ManageBusinessDefaults) ? $this->history(['notification_defaults_updated']) : collect(),
        ]);
    }
}

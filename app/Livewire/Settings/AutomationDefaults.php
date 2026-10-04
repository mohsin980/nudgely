<?php

namespace App\Livewire\Settings;

use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Team\TeamDirectory;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Who automations act for, assign tasks to and notify (owner and managers). These only pick
 * people; they never relax automation or email safety (those stay with the owner).
 */
#[Layout('components.layouts.app')]
#[Title('Automation Defaults')]
class AutomationDefaults extends Component
{
    use SettingsPage;

    public string $automationOwnerId = '';

    public string $taskAssigneeId = '';

    public string $notifyUserId = '';

    public function mount(): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);
        $defaults = $this->organization()->businessSettings();
        $this->automationOwnerId = (string) ($defaults->automationOwnerId() ?? '');
        $this->taskAssigneeId = (string) ($defaults->taskAssigneeId() ?? '');
        $this->notifyUserId = (string) ($defaults->notifyUserId() ?? '');
    }

    public function save(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);

        try {
            $settings->updateAutomationDefaults($this->user(), [
                'automation_owner_id' => $this->automationOwnerId, 'task_assignee_id' => $this->taskAssigneeId, 'notify_user_id' => $this->notifyUserId,
            ]);
        } catch (ValidationException $e) {
            $this->showErrors($e, ['automation_owner_id' => 'automationOwnerId', 'task_assignee_id' => 'taskAssigneeId', 'notify_user_id' => 'notifyUserId']);

            return;
        }

        $this->saved('Automation defaults saved.');
    }

    public function render(TeamDirectory $team)
    {
        return view('livewire.settings.automation-defaults', [
            'organization' => $this->organization(),
            'members' => $team->assignableOptions($this->organization()->id),
            'history' => $this->history(['automation_defaults_updated']),
        ]);
    }
}

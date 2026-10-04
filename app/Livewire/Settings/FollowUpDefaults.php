<?php

namespace App\Livewire\Settings;

use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * When a new follow-up is due by default (owner and managers): the schedule form and new
 * "Create follow-up" automation actions start from these.
 */
#[Layout('components.layouts.app')]
#[Title('Follow-up Defaults')]
class FollowUpDefaults extends Component
{
    use SettingsPage;

    public string $delayDays = '3';

    public string $time = '10:00';

    public function mount(): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);
        $defaults = $this->organization()->businessSettings();
        $this->delayDays = (string) $defaults->followUpDelayDays();
        $this->time = $defaults->followUpTime();
    }

    public function save(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);

        try {
            $settings->updateFollowUpDefaults($this->user(), ['delay_days' => $this->delayDays, 'time' => $this->time]);
        } catch (ValidationException $e) {
            $this->showErrors($e, ['delay_days' => 'delayDays']);

            return;
        }

        $this->saved('Follow-up defaults saved.');
    }

    public function render()
    {
        return view('livewire.settings.follow-up-defaults', [
            'organization' => $this->organization(),
            'history' => $this->history(['follow_up_defaults_updated']),
        ]);
    }
}

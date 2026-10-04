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
 * What a new estimate starts with (owner and managers). Changing a value on an estimate wins.
 */
#[Layout('components.layouts.app')]
#[Title('Estimate Defaults')]
class EstimateDefaults extends Component
{
    use SettingsPage;

    public string $validDays = '30';

    public string $notes = '';

    public string $taxRate = '';

    public function mount(): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);
        $defaults = $this->organization()->businessSettings();
        $this->validDays = (string) $defaults->estimateValidDays();
        $this->notes = $defaults->estimateNotes() ?? '';
        $this->taxRate = $defaults->estimateTaxRate() ?? '';
    }

    public function save(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessDefaults->value);

        try {
            $settings->updateEstimateDefaults($this->user(), ['valid_days' => $this->validDays, 'notes' => $this->notes, 'tax_rate' => $this->taxRate]);
        } catch (ValidationException $e) {
            $this->showErrors($e, ['valid_days' => 'validDays', 'tax_rate' => 'taxRate']);

            return;
        }

        $this->saved('Estimate defaults saved. New estimates will use them.');
    }

    public function render()
    {
        return view('livewire.settings.estimate-defaults', [
            'organization' => $this->organization(),
            'history' => $this->history(['estimate_defaults_updated']),
        ]);
    }
}

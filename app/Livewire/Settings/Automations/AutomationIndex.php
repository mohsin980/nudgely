<?php

namespace App\Livewire\Settings\Automations;

use App\Models\Automation;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Automations')]
class AutomationIndex extends Component
{
    use ResolvesAutomations;

    /**
     * Organization settings this page may change; anything else is rejected.
     */
    public const SETTINGS = ['automations_enabled', 'automatic_email_enabled', 'require_approval_for_email'];

    #[Locked]
    public ?int $confirmingDeletionId = null;

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public function mount(): void
    {
        $this->authorize('viewAny', Automation::class);
        $this->statusMessage = session('automation-status');
    }

    /**
     * @return Collection<int, Automation>
     */
    #[Computed]
    public function automations(): Collection
    {
        return $this->currentOrganization()->automations()
            ->withMax('runs as last_run_at', 'created_at')
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function templates(): array
    {
        return AutomationTemplates::all();
    }

    public function toggleSetting(string $setting): void
    {
        $this->authorize('create', Automation::class);
        abort_unless(in_array($setting, self::SETTINGS, true), 422);

        $organization = $this->currentOrganization();
        $organization->forceFill([$setting => ! $organization->{$setting}])->save();

        Log::info('Automation setting changed.', ['organization_id' => $organization->id, 'setting' => $setting, 'value' => $organization->{$setting}, 'user_id' => Auth::id()]);

        $this->flash('Automation settings saved.');
    }

    public function installTemplate(string $key, AutomationTemplates $templates): void
    {
        $this->authorize('create', Automation::class);
        abort_unless(array_key_exists($key, AutomationTemplates::all()), 404);

        $automation = $templates->install($this->currentOrganization(), Auth::user(), $key);

        $this->flash("“{$automation->name}” was added as a draft. Review it, then activate it.");
        unset($this->automations);
    }

    public function activate(int $automationId, AutomationBuilder $builder): void
    {
        $automation = $this->findAutomation($automationId);
        $this->authorize('update', $automation);

        try {
            $builder->activate($automation->load(['conditions', 'actions']), Auth::user());
        } catch (ValidationException $e) {
            $this->flash('“'.$automation->name.'” can’t be activated yet: '.collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->flash("“{$automation->name}” is active.");
        unset($this->automations);
    }

    public function pause(int $automationId, AutomationBuilder $builder): void
    {
        $automation = $this->findAutomation($automationId);
        $this->authorize('update', $automation);

        $builder->pause($automation, Auth::user());

        $this->flash("“{$automation->name}” is paused.");
        unset($this->automations);
    }

    public function confirmDelete(int $automationId): void
    {
        $automation = $this->findAutomation($automationId);
        $this->authorize('delete', $automation);

        $this->confirmingDeletionId = $automation->id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeletionId = null;
    }

    public function delete(): void
    {
        $automation = $this->findAutomation($this->confirmingDeletionId ?? abort(404));
        $this->authorize('delete', $automation);

        $automation->delete();
        Log::info('Automation deleted.', ['organization_id' => $automation->organization_id, 'automation_id' => $automation->id, 'user_id' => Auth::id()]);

        $this->confirmingDeletionId = null;
        $this->flash("“{$automation->name}” was deleted.");
        unset($this->automations);
    }

    public function render()
    {
        return view('livewire.settings.automations.automation-index', [
            'organization' => $this->currentOrganization(),
        ]);
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->statusMessage = $message;
        $this->statusType = $type;
    }
}

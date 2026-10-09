<?php

namespace App\Livewire\Automations;

use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\PlanLimitException;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationTemplates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The organization's automations, starter templates and email safety settings.
 */
#[Layout('components.layouts.app')]
#[Title('Automations')]
class AutomationIndex extends Component
{
    use ResolvesAutomations;

    /**
     * Organization settings this page may change; anything else is rejected.
     */
    public const SETTINGS = ['automations_enabled', 'automatic_email_enabled', 'require_approval_for_email'];

    #[Url(as: 'archived', except: false)]
    public bool $showArchived = false;

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public function mount(): void
    {
        $this->authorize('viewAny', Automation::class);
        $this->statusMessage = session('automation-status');
    }

    /**
     * One query with counts: number of actions, executions and the last execution.
     *
     * @return Collection<int, Automation>
     */
    #[Computed]
    public function automations(): Collection
    {
        return $this->currentOrganization()->automations()
            ->when(! $this->showArchived, fn ($q) => $q->where('status', '!=', AutomationStatus::Archived))
            ->withCount(['actions', 'runs as executions_count'])
            ->withMax('runs as last_run_at', 'created_at')
            ->orderByRaw("case status when 'active' then 0 when 'paused' then 1 when 'draft' then 2 else 3 end")
            ->latest()
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function archivedCount(): int
    {
        return $this->currentOrganization()->automations()->where('status', AutomationStatus::Archived)->count();
    }

    /**
     * Failed executions in the last 7 days.
     */
    #[Computed]
    public function recentFailures(): int
    {
        return AutomationRun::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('status', AutomationRunStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
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
        $this->authorize(Permission::ManageEmail->value); // automatic-email safety is the owner's call
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

        try {
            $automation = $templates->install($this->currentOrganization(), Auth::user(), $key);
        } catch (PlanLimitException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        session()->flash('automation-status', "“{$automation->name}” was added as a draft. Review it, then activate it.");
        $this->redirectRoute('automations.show', $automation->id, navigate: true);
    }

    public function render()
    {
        return view('livewire.automations.automation-index', [
            'organization' => $this->currentOrganization(),
            'starters' => AutomationTemplates::STARTERS,
        ]);
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->statusMessage = $message;
        $this->statusType = $type;
    }
}

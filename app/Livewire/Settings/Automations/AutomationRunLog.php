<?php

namespace App\Livewire\Settings\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Execution history of one automation, and the action-by-action detail of a run.
 */
#[Layout('components.layouts.app')]
class AutomationRunLog extends Component
{
    use ResolvesAutomations;
    use WithPagination;

    #[Locked]
    public int $automationId;

    #[Locked]
    public ?int $runId = null;

    public function mount(int $automationId, ?int $runId = null): void
    {
        $this->automationId = $automationId;
        $this->authorize('view', $this->automation);

        if ($runId !== null) {
            abort_unless($this->automation->runs()->whereKey($runId)->exists(), 404);
            $this->runId = $runId;
        }
    }

    #[Computed]
    public function automation(): Automation
    {
        return $this->findAutomation($this->automationId);
    }

    /**
     * @return LengthAwarePaginator<int, AutomationRun>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return $this->automation->runs()
            ->withCount('actionRuns')
            ->latest()
            ->latest('id')
            ->paginate(25);
    }

    /**
     * Scoped through the automation, so a run of another automation or organization is not found.
     */
    #[Computed]
    public function selectedRun(): ?AutomationRun
    {
        return $this->runId === null ? null : $this->automation->runs()
            ->with('actionRuns')
            ->whereKey($this->runId)
            ->first();
    }

    public function render()
    {
        return view('livewire.settings.automations.automation-run-log')
            ->title($this->automation->name.' · Runs');
    }
}

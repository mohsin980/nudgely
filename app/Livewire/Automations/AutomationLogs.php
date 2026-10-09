<?php

namespace App\Livewire\Automations;

use App\Enums\Automation\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Estimate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Execution logs of one automation, and the detail of one execution: trigger data,
 * conditions evaluated and each action's result.
 */
#[Layout('components.layouts.app')]
class AutomationLogs extends Component
{
    use ResolvesAutomations;
    use WithPagination;

    /** Filter values shown to people => run statuses. */
    public const FILTERS = ['success' => ['completed'], 'failed' => ['failed'], 'skipped' => ['skipped'], 'pending' => ['waiting', 'running']];

    #[Locked]
    public int $automationId;

    #[Locked]
    public ?int $runId = null;

    #[Url(except: '')]
    public string $status = '';

    public function mount(int $automationId, ?int $runId = null): void
    {
        $this->automationId = $automationId;
        $this->authorize('view', $this->automation);

        if ($runId !== null) {
            abort_unless($this->automation->runs()->whereKey($runId)->exists(), 404);
            $this->runId = $runId;
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
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
            ->when(isset(self::FILTERS[$this->status]), fn ($q) => $q->whereIn('status', self::FILTERS[$this->status]))
            ->with('customer:id,name')
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
            ->with(['actionRuns', 'customer:id,name'])
            ->whereKey($this->runId)
            ->first();
    }

    #[Computed]
    public function selectedEstimate(): ?Estimate
    {
        $id = $this->selectedRun?->context['estimate_id'] ?? null;

        return $id === null ? null : Estimate::query()->where('organization_id', $this->automation->organization_id)->find($id);
    }

    public function render()
    {
        return view('livewire.automations.automation-logs', [
            'organization' => $this->currentOrganization(),
            'filters' => array_keys(self::FILTERS),
            'pendingStatuses' => [AutomationRunStatus::Waiting, AutomationRunStatus::Running],
        ])->title($this->automation->name.' · Logs');
    }
}

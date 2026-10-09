<?php

namespace App\Livewire\Automations;

use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Models\Automation;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationSummary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One automation: what it does in plain English, its status and numbers, and the buttons to
 * change it. Every change goes through AutomationBuilder (validated, recorded in history).
 */
#[Layout('components.layouts.app')]
class ShowAutomation extends Component
{
    use ResolvesAutomations;

    #[Locked]
    public int $automationId;

    public bool $confirmArchive = false;

    public bool $confirmDelete = false;

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public function mount(int $automationId): void
    {
        $this->automationId = $automationId;
        $this->authorize('view', $this->automation);
        $this->statusMessage = session('automation-status');
    }

    #[Computed]
    public function automation(): Automation
    {
        return $this->findAutomation($this->automationId)->load(['conditions', 'actions', 'creator:id,name', 'updater:id,name']);
    }

    /**
     * @return array{executions: int, failed: int, last: ?string, next: ?string}
     */
    #[Computed]
    public function stats(): array
    {
        $row = $this->automation->runs()
            ->selectRaw('count(*) as executions')
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw('max(created_at) as last')
            ->selectRaw("min(resume_at) filter (where status = 'waiting') as next")
            ->toBase()->first();

        return ['executions' => (int) $row->executions, 'failed' => (int) $row->failed, 'last' => $row->last, 'next' => $row->next];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function activationErrors(): array
    {
        return $this->automation->isActive() ? [] : app(AutomationBuilder::class)->activationErrors($this->automation);
    }

    public function activate(AutomationBuilder $builder): void
    {
        $this->authorize('update', $this->automation);

        try {
            $builder->activate($this->automation, Auth::user());
        } catch (ValidationException $e) {
            $this->flash('“'.$this->automation->name.'” can’t be activated yet: '.collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->refreshAutomation("“{$this->automation->name}” is active.");
    }

    public function pause(AutomationBuilder $builder): void
    {
        $this->authorize('update', $this->automation);
        $builder->pause($this->automation, Auth::user());
        $this->refreshAutomation("“{$this->automation->name}” is paused. Nothing new will start, and waiting runs will be skipped.");
    }

    public function archive(AutomationBuilder $builder): void
    {
        $this->authorize('update', $this->automation);
        $builder->archive($this->automation, Auth::user());
        $this->confirmArchive = false;
        $this->refreshAutomation("“{$this->automation->name}” is archived. Its history and logs are kept.");
    }

    public function restore(AutomationBuilder $builder): void
    {
        $this->authorize('update', $this->automation);
        $builder->restore($this->automation, Auth::user());
        $this->refreshAutomation("“{$this->automation->name}” is back as a draft.");
    }

    public function duplicate(AutomationBuilder $builder): void
    {
        $this->authorize('create', Automation::class);
        $this->authorize('view', $this->automation);

        $copy = $builder->duplicate($this->automation, Auth::user());

        session()->flash('automation-status', "“{$copy->name}” was created as a draft.");
        $this->redirectRoute('automations.edit', $copy->id, navigate: true);
    }

    /**
     * Only an automation that never ran can be deleted; otherwise it is archived (logs are kept).
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->automation);

        if ($this->automation->runs()->exists()) {
            $this->confirmDelete = false;
            $this->flash('This automation has run before, so it can’t be deleted. Archive it instead: its logs are kept.', 'error');

            return;
        }

        $this->automation->delete();
        Log::info('Automation deleted.', ['organization_id' => $this->automation->organization_id, 'automation_id' => $this->automation->id, 'user_id' => Auth::id()]);

        session()->flash('automation-status', "“{$this->automation->name}” was deleted.");
        $this->redirectRoute('automations.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.automations.show-automation', [
            'organization' => $this->currentOrganization(),
            'summary' => AutomationSummary::of($this->automation),
            'history' => $this->automation->history()->with('user:id,name')->limit(20)->get(),
            'archived' => $this->automation->status === AutomationStatus::Archived,
            'waitingRuns' => $this->automation->runs()->where('status', AutomationRunStatus::Waiting)->count(),
        ])->title($this->automation->name);
    }

    private function refreshAutomation(string $message): void
    {
        unset($this->automation, $this->stats, $this->activationErrors);
        $this->flash($message);
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->statusMessage = $message;
        $this->statusType = $type;
    }
}

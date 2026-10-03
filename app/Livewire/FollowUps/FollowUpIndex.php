<?php

namespace App\Livewire\FollowUps;

use App\Enums\FollowUpStatus;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Livewire\Concerns\ManagesFollowUps;
use App\Models\FollowUp;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "What do I need to follow up on?": overdue, due today and upcoming first; history after.
 */
#[Layout('components.layouts.app')]
#[Title('Follow-Ups')]
class FollowUpIndex extends Component
{
    use ManagesFollowUps;

    /**
     * Optional section filter from the dashboard cards: "overdue" or "today".
     */
    #[Url(except: '')]
    public string $filter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', FollowUp::class);

        if (! in_array($this->filter, ['', 'overdue', 'today'], true)) {
            $this->filter = '';
        }

        if (request()->boolean('schedule')) {
            $this->openScheduleForm();
        }
    }

    /**
     * Open follow-ups grouped by the organization's today.
     *
     * @return array{overdue: Collection<int, FollowUp>, due_today: Collection<int, FollowUp>, upcoming: Collection<int, FollowUp>}
     */
    #[Computed]
    public function sections(): array
    {
        [$start, $end] = app(FollowUpService::class)->today($this->organization());

        $open = FollowUp::query()
            ->forOrganization($this->organization())
            ->open()
            ->with(['customer:id,name', 'assignee:id,name'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        return [
            'overdue' => $open->filter(fn (FollowUp $f) => $f->due_at->lt($start))->values(),
            'due_today' => $open->filter(fn (FollowUp $f) => $f->due_at->betweenIncluded($start, $end))->values(),
            'upcoming' => $open->filter(fn (FollowUp $f) => $f->due_at->gt($end))->values(),
        ];
    }

    /**
     * @return Collection<int, FollowUp>
     */
    #[Computed]
    public function completed(): Collection
    {
        return $this->history([FollowUpStatus::Completed], 'completed_at');
    }

    /**
     * @return Collection<int, FollowUp>
     */
    #[Computed]
    public function closed(): Collection
    {
        return $this->history([FollowUpStatus::Cancelled, FollowUpStatus::Skipped, FollowUpStatus::Failed], 'updated_at');
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function customers(): \Illuminate\Support\Collection
    {
        return $this->organization()->customers()->orderBy('name')->limit(500)->pluck('name', 'id');
    }

    public function render()
    {
        return view('livewire.follow-ups.follow-up-index', ['organization' => $this->organization()]);
    }

    protected function followUpTarget(): array
    {
        $customer = $this->organization()->customers()->find((int) $this->scheduleCustomerId)
            ?? throw new InvalidFollowUpException('Choose a customer.');

        return [$customer, null];
    }

    protected function followUpsChanged(): void
    {
        unset($this->sections, $this->completed, $this->closed);
    }

    /**
     * @param  list<FollowUpStatus>  $statuses
     * @return Collection<int, FollowUp>
     */
    private function history(array $statuses, string $orderBy): Collection
    {
        return FollowUp::query()
            ->forOrganization($this->organization())
            ->whereIn('status', $statuses)
            ->with(['customer:id,name', 'assignee:id,name'])
            ->latest($orderBy)
            ->limit(20)
            ->get();
    }
}

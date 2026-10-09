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
     * The page lists at most this many follow-ups per section; the section headings still show the true totals.
     */
    public const SECTION_LIMIT = 100;

    /**
     * Open follow-ups grouped by the organization's today. Each section is one bounded, index-backed query:
     * a tenant with thousands of open follow-ups no longer loads all of them on every render.
     *
     * @return array{overdue: Collection<int, FollowUp>, due_today: Collection<int, FollowUp>, upcoming: Collection<int, FollowUp>}
     */
    #[Computed]
    public function sections(): array
    {
        [$overdue, $dueToday, $upcoming] = $this->sectionQueries();

        return [
            'overdue' => $overdue->limit(self::SECTION_LIMIT)->get(),
            'due_today' => $dueToday->limit(self::SECTION_LIMIT)->get(),
            'upcoming' => $upcoming->limit(self::SECTION_LIMIT)->get(),
        ];
    }

    /**
     * The true size of each section, counted in the database (the lists above are capped).
     *
     * @return array{overdue: int, due_today: int, upcoming: int}
     */
    #[Computed]
    public function sectionCounts(): array
    {
        [$overdue, $dueToday, $upcoming] = $this->sectionQueries();

        return ['overdue' => $overdue->count(), 'due_today' => $dueToday->count(), 'upcoming' => $upcoming->count()];
    }

    /**
     * @return array{0: Builder, 1: Builder, 2: Builder}
     */
    private function sectionQueries(): array
    {
        [$start, $end] = app(FollowUpService::class)->today($this->organization());

        $open = fn () => FollowUp::query()
            ->forOrganization($this->organization())
            ->open()
            ->with(['customer:id,name', 'assignee:id,name'])
            ->orderBy('due_at')
            ->orderBy('id');

        return [
            $open()->where('due_at', '<', $start),
            $open()->whereBetween('due_at', [$start, $end]),
            $open()->where('due_at', '>', $end),
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
        unset($this->sections, $this->sectionCounts, $this->completed, $this->closed);
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

<?php

namespace App\Livewire\Estimates;

use App\Exceptions\Estimates\EstimateException;
use App\Livewire\Concerns\ManagesFollowUps;
use App\Models\Estimate;
use App\Models\Message;
use App\Services\Conversations\TimelineService;
use App\Services\Estimates\EstimateDocument;
use App\Services\Estimates\EstimateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One estimate: the document as the customer sees it, status, activity, versions, and the
 * actions that fit its status (edit / send a draft, revise or cancel a sent one, follow up).
 */
#[Layout('components.layouts.app')]
class ShowEstimate extends Component
{
    use ManagesFollowUps;

    #[Locked]
    public int $estimateId;

    public ?string $statusMessage = null;

    public string $statusMessageType = 'success';

    public bool $confirmCancel = false;

    public function mount(int $estimateId): void
    {
        $this->estimateId = $estimateId;
        $this->authorize('view', $this->estimate); // 404 for another organization's estimate

        if (session('estimate-error')) {
            [$this->statusMessage, $this->statusMessageType] = [session('estimate-error'), 'error'];
        } elseif (session('estimate-status')) {
            $this->statusMessage = session('estimate-status');
        }
    }

    /**
     * Looked up inside the user's organization.
     */
    #[Computed]
    public function estimate(): Estimate
    {
        return $this->organization()->estimates()->whereKey($this->estimateId)->with(['customer', 'conversation'])->first() ?? abort(404);
    }

    #[Computed]
    public function document(): EstimateDocument
    {
        return EstimateDocument::for($this->estimate, $this->organization());
    }

    /**
     * Every version of this estimate, newest first.
     *
     * @return Collection<int, Estimate>
     */
    #[Computed]
    public function versions(): Collection
    {
        $root = $this->estimate->rootId();

        return $this->organization()->estimates()
            ->where(fn ($q) => $q->whereKey($root)->orWhere('revision_of_id', $root))
            ->orderByDesc('revision')
            ->get(['id', 'estimate_number', 'revision', 'status', 'total', 'currency', 'sent_at', 'created_at']);
    }

    #[Computed]
    public function activity(): Collection
    {
        return app(TimelineService::class)->forEstimate($this->estimate);
    }

    #[Computed]
    public function estimateFollowUps(): Collection
    {
        return $this->estimate->followUps()->where('organization_id', $this->organization()->id)->open()->orderBy('due_at')->get();
    }

    #[Computed]
    public function failedSend(): ?Message
    {
        return app(EstimateService::class)->failedSend($this->estimate);
    }

    #[Computed]
    public function sending(): bool
    {
        return app(EstimateService::class)->isSending($this->estimate);
    }

    public function send(EstimateService $estimates): void
    {
        $this->authorize('update', $this->estimate);

        $this->act(fn () => $estimates->send(Auth::user(), $this->estimate), 'Sending estimate…');
    }

    public function revise(EstimateService $estimates): void
    {
        $this->authorize('update', $this->estimate);

        try {
            $revision = $estimates->revise(Auth::user(), $this->estimate);
        } catch (EstimateException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        session()->flash('estimate-status', "Revision {$revision->displayNumber()} created. The customer still has {$this->estimate->displayNumber()} until you send this one.");
        $this->redirectRoute('estimates.edit', $revision->id, navigate: true);
    }

    public function cancel(EstimateService $estimates): void
    {
        $this->authorize('update', $this->estimate);
        $this->confirmCancel = false;

        $this->act(fn () => $estimates->cancel(Auth::user(), $this->estimate), 'Estimate cancelled. The customer\'s link no longer works.');
    }

    public function render()
    {
        return view('livewire.estimates.show-estimate', ['organization' => $this->organization()])
            ->title('Estimate '.$this->estimate->displayNumber());
    }

    protected function followUpTarget(): array
    {
        return [$this->estimate->customer ?? abort(404), $this->estimate->conversation, $this->estimate];
    }

    protected function defaultFollowUpNotes(): string
    {
        return 'Follow up on Estimate '.$this->estimate->displayNumber();
    }

    protected function followUpsChanged(): void
    {
        unset($this->estimateFollowUps, $this->activity);
    }

    private function act(\Closure $action, string $success): void
    {
        try {
            $action();
            $this->flash($success);
        } catch (EstimateException $e) {
            $this->flash($e->getMessage(), 'error');
        }

        unset($this->estimate, $this->document, $this->versions, $this->activity, $this->failedSend, $this->sending, $this->estimateFollowUps);
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->statusMessage = $message;
        $this->statusMessageType = $type;
    }
}

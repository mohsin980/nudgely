<?php

namespace App\Livewire\Customers;

use App\Enums\ConversationStatus;
use App\Exceptions\Conversations\ConversationActionException;
use App\Livewire\Concerns\ManagesFollowUps;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Task;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\TimelineService;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A customer's workspace: details, active conversation, follow-ups, tasks and full activity.
 */
#[Layout('components.layouts.app')]
class ShowCustomer extends Component
{
    use ManagesFollowUps;

    #[Locked]
    public int $customerId;

    public int $timelineLimit = 30;

    public bool $showEmailForm = false;

    public string $emailSubject = '';

    public string $emailBody = '';

    public ?string $statusMessage = null;

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->authorize('view', $this->customer); // 404 for another organization's customer
        $this->statusMessage = session('customer-status');
    }

    /**
     * Looked up inside the user's organization.
     */
    #[Computed]
    public function customer(): Customer
    {
        return $this->organization()->customers()->whereKey($this->customerId)->first() ?? abort(404);
    }

    /**
     * Most recently active first.
     *
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return $this->customer->conversations()
            ->where('organization_id', $this->organization()->id)
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'inbound')->whereNull('read_at')])
            ->with(['latestMessage' => fn ($q) => $q->select('messages.id', 'messages.conversation_id', 'messages.direction', 'messages.status', 'messages.created_at')->selectRaw('left(messages.body_text, 200) as excerpt')])
            ->orderByRaw('last_message_at desc nulls last')
            ->limit(20)
            ->get();
    }

    /**
     * The conversation "Send Email" continues: the most recent one that isn't closed.
     */
    #[Computed]
    public function activeConversation(): ?Conversation
    {
        return $this->conversations->first(fn (Conversation $c) => $c->status !== ConversationStatus::Closed);
    }

    /**
     * @return Collection<int, FollowUp>
     */
    #[Computed]
    public function followUps(): Collection
    {
        return $this->customer->followUps()
            ->where('organization_id', $this->organization()->id)
            ->with('assignee:id,name')
            ->orderByRaw("case when status in ('pending', 'due') then 0 else 1 end")
            ->orderByRaw("case when status in ('pending', 'due') then due_at end asc")
            ->latest('updated_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->customer->tasks()
            ->with('assignee:id,name')
            ->where('organization_id', $this->organization()->id)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /**
     * Latest versions first; superseded (replaced) versions are still listed with their status.
     *
     * @return Collection<int, Estimate>
     */
    #[Computed]
    public function estimates(): Collection
    {
        return $this->customer->estimates()
            ->where('organization_id', $this->organization()->id)
            ->latest()->latest('id')
            ->limit(20)
            ->get(['id', 'estimate_number', 'revision', 'status', 'title', 'total', 'currency', 'sent_at', 'created_at']);
    }

    #[Computed]
    public function timeline(): \Illuminate\Support\Collection
    {
        return app(TimelineService::class)->build($this->customer, limit: $this->timelineLimit);
    }

    public function loadMoreActivity(): void
    {
        $this->timelineLimit = min($this->timelineLimit + 30, 300);
    }

    public function openEmailForm(): void
    {
        if ($this->activeConversation !== null) {
            $this->redirectRoute('inbox.show', ['conversationId' => $this->activeConversation->id, 'compose' => 1], navigate: true);

            return;
        }

        $this->authorize('update', $this->customer);
        $this->resetErrorBag();
        $this->showEmailForm = true;
    }

    /**
     * Email a customer without an open conversation: starts a new conversation.
     */
    public function sendEmail(ConversationService $conversations): void
    {
        $this->authorize('update', $this->customer);
        $this->resetErrorBag();

        try {
            $conversation = $conversations->startWithEmail(Auth::user(), $this->customer, $this->emailSubject, $this->emailBody);
        } catch (ConversationActionException $e) {
            $e->errors === [] ? $this->addError('email', $e->getMessage()) : collect($e->errors)->each(fn ($m, $f) => $this->addError('email'.ucfirst($f), $m));

            return;
        }

        $this->redirectRoute('inbox.show', $conversation->id, navigate: true);
    }

    public function completeTask(int $taskId, TaskService $tasks): void
    {
        $task = Task::query()->where('organization_id', $this->organization()->id)->where('customer_id', $this->customer->id)->whereKey($taskId)->first() ?? abort(404);
        $this->authorize('update', $this->customer);

        $tasks->complete(Auth::user(), $task);
        unset($this->tasks, $this->timeline);
    }

    public function render()
    {
        return view('livewire.customers.show-customer', ['organization' => $this->organization()])
            ->title($this->customer->name);
    }

    protected function followUpTarget(): array
    {
        return [$this->customer, $this->activeConversation];
    }

    protected function followUpsChanged(): void
    {
        unset($this->followUps, $this->timeline);
    }
}

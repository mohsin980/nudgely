<?php

namespace App\Livewire\Inbox;

use App\Enums\ConfidenceLevel;
use App\Enums\ConversationCloseReason;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\MessageDirection;
use App\Enums\TaskPriority;
use App\Exceptions\Conversations\ConversationActionException;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Livewire\Concerns\ManagesFollowUps;
use App\Models\Conversation;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Task;
use App\Services\AI\CustomerReplyClassificationService;
use App\Services\AI\ReplyReviewPolicy;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\TimelineService;
use App\Services\Email\EmailHtmlSanitizer;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One conversation: the chronological timeline (customer, business, system and automation
 * entries), the email composer, AI classification (with human correction), follow-ups, tasks
 * and status. Opening it marks this conversation's customer replies as read.
 *
 * Changes go through ConversationService / TaskService / FollowUpService, which check the
 * organization themselves; nothing here sends email directly or runs automations.
 */
#[Layout('components.layouts.app')]
class ShowConversation extends Component
{
    use ManagesFollowUps;

    #[Locked]
    public int $conversationId;

    public ?string $statusMessage = null;

    public string $statusMessageType = 'info';

    public int $messageLimit = 50;

    // Composer
    public string $replySubject = '';

    public string $replyBody = '';

    public bool $composerOpen = false;

    // Close
    public bool $showCloseForm = false;

    public string $closeReason = 'completed';

    public string $closeNote = '';

    // Classification correction
    public bool $showOverrideForm = false;

    public string $overrideIntent = '';

    public string $overrideReason = '';

    // Task
    public bool $showTaskForm = false;

    public string $taskTitle = '';

    public string $taskPriority = 'medium';

    public function mount(int $conversationId, ConversationService $conversations): void
    {
        $this->conversationId = $conversationId;
        $this->authorize('view', $this->conversation);

        $subject = (string) $this->conversation->subject;
        $this->replySubject = $subject === '' ? '' : (str_starts_with(strtolower($subject), 're:') ? $subject : 'Re: '.$subject);
        $this->composerOpen = request()->boolean('compose');

        // Reading the conversation marks only its own customer replies as read.
        $conversations->markRead(Auth::user(), $this->conversation);
    }

    /**
     * Looked up inside the user's organization, so another organization's conversation is a 404.
     */
    #[Computed]
    public function conversation(): Conversation
    {
        $organizationId = Auth::user()->organization_id ?? abort(403);

        return Conversation::query()
            ->where('organization_id', $organizationId)
            ->with('customer')
            ->whereKey($this->conversationId)
            ->first() ?? abort(404);
    }

    /**
     * The latest messages (oldest first); "Load earlier messages" raises the limit.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation->messages()
            ->with('classifications.overrider:id,name')
            ->orderByRaw('coalesce(received_at, sent_at, created_at) desc')
            ->orderByDesc('id')
            ->limit($this->messageLimit + 1)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function hasEarlierMessages(): bool
    {
        return $this->messages->count() > $this->messageLimit;
    }

    /**
     * Messages interleaved with system/automation events, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, array{type: string, at: \DateTimeInterface, item: mixed}>
     */
    #[Computed]
    public function feed(): \Illuminate\Support\Collection
    {
        $messages = $this->hasEarlierMessages ? $this->messages->slice(1)->values() : $this->messages;
        $since = $this->hasEarlierMessages ? $messages->first()?->occurredAt() : null;

        $events = app(TimelineService::class)
            ->build($this->conversation->customer, $this->conversation->id, limit: 100, withMessages: false, withClassifications: false)
            ->filter(fn ($entry) => $since === null || $entry->at >= $since);

        return $messages->map(fn (Message $m) => ['type' => 'message', 'at' => $m->occurredAt(), 'item' => $m])
            ->concat($events->map(fn ($e) => ['type' => 'event', 'at' => $e->at, 'item' => $e]))
            ->sortBy(fn ($row) => [$row['at']->getTimestamp(), $row['type'] === 'message' ? 0 : 1])
            ->values();
    }

    /**
     * The classification that currently applies (AI or a person's correction).
     */
    #[Computed]
    public function currentClassification(): ?MessageClassification
    {
        return $this->conversation->latestClassification()->with(['overrider:id,name'])->first();
    }

    /**
     * Open follow-ups for this conversation, plus the latest finished one (e.g. "skipped: customer replied").
     *
     * @return Collection<int, FollowUp>
     */
    #[Computed]
    public function conversationFollowUps(): Collection
    {
        $base = fn () => $this->conversation->followUps()
            ->where('organization_id', $this->conversation->organization_id)
            ->with('assignee:id,name');

        $open = $base()->open()->orderBy('due_at')->get();
        $latestClosed = $base()->whereNotIn('status', ['pending', 'due'])->latest('updated_at')->first();

        return $latestClosed === null ? $open : $open->push($latestClosed);
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->where('organization_id', $this->conversation->organization_id)
            ->where('conversation_id', $this->conversation->id)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function loadEarlierMessages(): void
    {
        $this->messageLimit = min($this->messageLimit + 50, 1000);
        $this->resetFeed();
    }

    public function sendReply(ConversationService $conversations): void
    {
        $this->authorize('update', $this->conversation);
        $this->resetErrorBag();

        try {
            $conversations->reply(Auth::user(), $this->conversation, $this->replySubject, $this->replyBody);
        } catch (ConversationActionException $e) {
            $this->failWith($e, 'reply');

            return;
        }

        $this->replyBody = '';
        $this->composerOpen = false;
        $this->flash('Email sent to '.$this->conversation->customer->email.'.', 'success');
        unset($this->conversation);
        $this->resetFeed();
    }

    public function setStatus(string $status, ConversationService $conversations): void
    {
        $this->authorize('update', $this->conversation);
        $target = ConversationStatus::tryFrom($status) ?? abort(422);

        if ($target === ConversationStatus::Closed) {
            $this->showCloseForm = true;

            return;
        }

        $conversations->setStatus(Auth::user(), $this->conversation, $target);
        $this->flash('Status changed to '.$target->label().'.', 'success');
        unset($this->conversation);
        $this->resetFeed();
    }

    public function closeConversation(ConversationService $conversations): void
    {
        $this->authorize('update', $this->conversation);
        $reason = ConversationCloseReason::tryFrom($this->closeReason) ?? abort(422);

        $conversations->close(Auth::user(), $this->conversation, $reason, $this->closeNote);

        $this->showCloseForm = false;
        $this->closeNote = '';
        $this->flash('Conversation closed. Its automated follow-ups were skipped.', 'success');
        unset($this->conversation, $this->conversationFollowUps);
        $this->resetFeed();
    }

    public function reopenConversation(ConversationService $conversations): void
    {
        $this->authorize('update', $this->conversation);

        $conversations->reopen(Auth::user(), $this->conversation);
        $this->flash('Conversation reopened.', 'success');
        unset($this->conversation);
        $this->resetFeed();
    }

    public function overrideClassification(ConversationService $conversations): void
    {
        $this->authorize('update', $this->conversation);
        $this->resetErrorBag();
        $intent = CustomerReplyIntent::tryFrom($this->overrideIntent);

        if ($intent === null) {
            $this->addError('overrideIntent', 'Choose an intent.');

            return;
        }

        try {
            $conversations->overrideClassification(Auth::user(), $this->conversation, $intent, $this->overrideReason);
        } catch (ConversationActionException $e) {
            $this->failWith($e, 'overrideIntent');

            return;
        }

        $this->showOverrideForm = false;
        $this->overrideReason = '';
        $this->flash('Classification changed to '.$intent->label().'. The AI\'s original classification is kept in the history.', 'success');
        unset($this->conversation, $this->currentClassification, $this->messages);
        $this->resetFeed();
    }

    public function createTask(TaskService $tasks): void
    {
        $this->authorize('update', $this->conversation);
        $this->resetErrorBag();

        try {
            $tasks->create(Auth::user(), $this->conversation->customer, $this->taskTitle, TaskPriority::tryFrom($this->taskPriority) ?? TaskPriority::Medium, $this->conversation);
        } catch (ConversationActionException $e) {
            $this->failWith($e, 'taskTitle');

            return;
        }

        $this->showTaskForm = false;
        $this->taskTitle = '';
        unset($this->tasks);
        $this->resetFeed();
    }

    public function completeTask(int $taskId, TaskService $tasks): void
    {
        $this->authorize('update', $this->conversation);
        $task = Task::query()->where('organization_id', $this->conversation->organization_id)->where('conversation_id', $this->conversation->id)->whereKey($taskId)->first() ?? abort(404);

        $tasks->complete(Auth::user(), $task);
        unset($this->tasks);
        $this->resetFeed();
    }

    /**
     * Ask for a new classification of one customer reply. Earlier classifications are kept.
     */
    public function reclassify(int $messageId): void
    {
        $this->authorize('reclassify', $this->conversation);

        $message = $this->conversation->messages()
            ->where('direction', MessageDirection::Inbound)
            ->whereKey($messageId)
            ->first() ?? abort(404);

        abort_unless(app(CustomerReplyClassificationService::class)->isClassifiable($message), 422);

        // Each reclassification is a paid AI call; keep it deliberate.
        $key = 'reclassify:'.$this->conversation->organization_id;
        if (! RateLimiter::attempt($key, 30, fn () => true, 3600)) {
            $this->statusMessage = 'Too many reclassification requests. Please try again later.';

            return;
        }

        dispatch(ClassifyCustomerReplyJob::reclassification($message));

        Log::info('Reply reclassification requested.', [
            'organization_id' => $this->conversation->organization_id,
            'message_id' => $message->id,
            'user_id' => Auth::id(),
        ]);

        $this->statusMessage = 'Reclassification requested. The new AI insight will appear when it is ready.';
        unset($this->messages);
    }

    public function confidenceLevel(float $confidence): ConfidenceLevel
    {
        return app(ReplyReviewPolicy::class)->confidenceLevel($confidence);
    }

    public function canReclassify(): bool
    {
        return Auth::user()->can('reclassify', $this->conversation);
    }

    /**
     * Email HTML is sanitized on arrival and again here, right before rendering.
     */
    public function safeHtml(?string $html): ?string
    {
        return app(EmailHtmlSanitizer::class)->sanitize($html);
    }

    protected function followUpTarget(): array
    {
        return [$this->conversation->customer, $this->conversation];
    }

    protected function followUpsChanged(): void
    {
        unset($this->conversationFollowUps);
        $this->resetFeed();
    }

    public function render()
    {
        return view('livewire.inbox.show-conversation', [
            'organization' => $this->conversation->organization,
            'closeReasons' => ConversationCloseReason::cases(),
            'intents' => CustomerReplyIntent::cases(),
            'statuses' => ConversationStatus::cases(),
        ])->title(($this->conversation->subject ?? 'Conversation').' · Conversations');
    }

    private function resetFeed(): void
    {
        unset($this->messages, $this->hasEarlierMessages, $this->feed);
    }

    private function flash(string $message, string $type = 'info'): void
    {
        $this->statusMessage = $message;
        $this->statusMessageType = $type;
    }

    private function failWith(ConversationActionException $e, string $field): void
    {
        if ($e->errors === []) {
            $this->addError($field, $e->getMessage());

            return;
        }

        foreach ($e->errors as $key => $message) {
            $this->addError(in_array($key, ['subject', 'body'], true) ? 'reply'.ucfirst($key) : $key, $message);
        }
    }
}

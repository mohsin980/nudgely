<?php

namespace App\Livewire\Inbox;

use App\Enums\AttentionPriority;
use App\Enums\ConversationStatus;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Conversations\ConversationDirectory;
use App\Services\Customers\CustomerDirectory;
use App\Services\Dashboard\AttentionPriorityRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /conversations: most recently active first, filtered and paged in the database.
 */
#[Layout('components.layouts.app')]
#[Title('Conversations')]
class ConversationList extends Component
{
    use WithPagination;

    /**
     * Quick filters kept from the original inbox (and used by dashboard links).
     */
    public const FILTERS = [
        'all' => 'All',
        'waiting' => 'Waiting for you',
        'needs_attention' => 'Needs attention',
        'ready_to_book' => 'Ready to book',
        'price_objection' => 'Price objection',
        'question' => 'Questions',
        'not_interested' => 'Not interested',
    ];

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $intent = '';

    #[Url(as: 'follow_up', except: '')]
    public string $followUp = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Conversation::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function organization(): Organization
    {
        return Auth::user()->organization ?? abort(403);
    }

    #[Computed]
    public function conversations()
    {
        $filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : 'all';

        return app(ConversationDirectory::class)->paginate($this->organization, [
            'search' => $this->search,
            'status' => $this->status,
            'priority' => $this->priority,
            // Quick intent filters map onto the intent filter.
            'intent' => $this->intent !== '' ? $this->intent : (in_array($filter, ['ready_to_book', 'price_objection', 'question', 'not_interested'], true) ? $filter : ''),
            'follow_up' => $this->followUp,
            'quick' => $filter,
        ]);
    }

    /**
     * Replies that came through a valid reply address but from an unexpected sender.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function needsReview(): Collection
    {
        return $this->organization->messages()
            ->where('status', MessageStatus::NeedsReview)
            ->latest('received_at')
            ->limit(20)
            ->get();
    }

    public function priorityOf(Conversation $conversation): AttentionPriority
    {
        return app(AttentionPriorityRules::class)->forConversationModel($conversation);
    }

    public function render()
    {
        return view('livewire.inbox.conversation-list', [
            'statuses' => ConversationStatus::cases(),
            'priorities' => AttentionPriority::cases(),
            'intents' => CustomerDirectory::INTENTS,
            'filtering' => $this->filter !== 'all' || ($this->search.$this->status.$this->priority.$this->intent.$this->followUp) !== '',
        ]);
    }
}

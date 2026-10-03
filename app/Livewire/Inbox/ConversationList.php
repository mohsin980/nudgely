<?php

namespace App\Livewire\Inbox;

use App\Enums\CustomerReplyIntent;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Inbox')]
class ConversationList extends Component
{
    /**
     * Filter key => label. Intent filters use the latest classified customer reply.
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

    public function mount(): void
    {
        $this->authorize('viewAny', Conversation::class);
    }

    #[Computed]
    public function organization(): Organization
    {
        return Auth::user()->organization ?? abort(403);
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : 'all';

        return $this->organization->conversations()
            ->with('customer')
            ->when($filter === 'waiting', fn ($query) => $query->waitingForBusiness())
            ->when($filter === 'needs_attention', fn ($query) => $query->where('needs_attention', true))
            ->when(CustomerReplyIntent::tryFrom($filter), fn ($query, CustomerReplyIntent $intent) => $query->where('latest_intent', $intent))
            ->orderByRaw('last_message_at desc nulls last')
            ->limit(100)
            ->get();
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
}

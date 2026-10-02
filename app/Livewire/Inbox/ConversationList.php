<?php

namespace App\Livewire\Inbox;

use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Inbox')]
class ConversationList extends Component
{
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
        return $this->organization->conversations()
            ->with('customer')
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

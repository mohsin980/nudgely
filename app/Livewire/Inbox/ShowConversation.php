<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Email\EmailHtmlSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ShowConversation extends Component
{
    #[Locked]
    public int $conversationId;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->authorize('view', $this->conversation);
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
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation->messages()
            ->orderByRaw('coalesce(received_at, sent_at, created_at)')
            ->orderBy('id')
            ->get();
    }

    /**
     * Email HTML is sanitized on arrival and again here, right before rendering.
     */
    public function safeHtml(?string $html): ?string
    {
        return app(EmailHtmlSanitizer::class)->sanitize($html);
    }

    public function render()
    {
        return view('livewire.inbox.show-conversation')
            ->title(($this->conversation->subject ?? 'Conversation').' · Inbox');
    }
}

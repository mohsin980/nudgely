<?php

namespace App\Livewire\Inbox;

use App\Enums\ConfidenceLevel;
use App\Enums\MessageDirection;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\CustomerReplyClassificationService;
use App\Services\AI\ReplyReviewPolicy;
use App\Services\Email\EmailHtmlSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ShowConversation extends Component
{
    #[Locked]
    public int $conversationId;

    public ?string $statusMessage = null;

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
            ->with('classifications')
            ->orderByRaw('coalesce(received_at, sent_at, created_at)')
            ->orderBy('id')
            ->get();
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

    public function render()
    {
        return view('livewire.inbox.show-conversation')
            ->title(($this->conversation->subject ?? 'Conversation').' · Inbox');
    }
}

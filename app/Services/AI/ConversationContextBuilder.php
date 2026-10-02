<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\Data\CustomerConversationContext;
use Illuminate\Support\Str;

/**
 * Builds the minimal context the classifier needs for one customer reply.
 *
 * Sent: business display name, customer first name, conversation subject, the reply
 * (quoted history trimmed) and the last few messages, each truncated.
 * Never sent: email addresses, surnames, IDs, metadata, headers or anything outside the conversation.
 */
class ConversationContextBuilder
{
    public function build(Message $message): CustomerConversationContext
    {
        $conversation = Conversation::query()
            ->where('organization_id', $message->organization_id)
            ->with(['customer', 'organization'])
            ->findOrFail($message->conversation_id);

        $previous = $conversation->messages()
            ->whereKeyNot($message->id)
            ->whereRaw('coalesce(received_at, sent_at, created_at) <= ?', [$message->occurredAt()])
            ->orderByRaw('coalesce(received_at, sent_at, created_at) desc')
            ->orderByDesc('id')
            ->limit((int) config('ai.classification.context.previous_messages', 4))
            ->get()
            ->reverse()
            ->values();

        $businessName = $conversation->messages()
            ->where('direction', 'outbound')
            ->whereNotNull('from_name')
            ->latest('id')
            ->value('from_name') ?? $conversation->organization->name;

        return new CustomerConversationContext(
            businessName: $businessName,
            customerFirstName: Str::before(trim($conversation->customer->name), ' ') ?: null,
            topic: $conversation->subject,
            latestReply: $this->text($message, (int) config('ai.classification.context.max_latest_chars', 4000)),
            previousMessages: $previous
                ->map(fn (Message $m) => [
                    'from' => $m->isInbound() ? 'customer' : 'business',
                    'text' => $this->text($m, (int) config('ai.classification.context.max_previous_chars', 1500)),
                ])
                ->filter(fn (array $m) => $m['text'] !== '')
                ->values()
                ->all(),
        );
    }

    /**
     * Plain text of a message with quoted earlier emails removed, truncated.
     */
    public function text(Message $message, int $maxChars): string
    {
        $text = $message->body_text ?? html_entity_decode(strip_tags((string) $message->body_html), ENT_QUOTES | ENT_HTML5);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Simple quoted-reply trimming: stop at "On … wrote:" and drop "> " lines.
        $text = preg_split('/^\s*On .{1,200}wrote:\s*$/mu', $text)[0] ?? $text;
        $lines = array_filter(explode("\n", $text), fn (string $line) => ! str_starts_with(ltrim($line), '>'));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return Str::limit($text, $maxChars, '…');
    }
}

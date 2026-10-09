<?php

namespace App\Services\Conversations;

use App\Enums\AttentionPriority;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\Customers\CustomerDirectory;
use App\Services\Dashboard\AttentionPriorityRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The conversation list: filters, search and pagination in the database, one organization only.
 * Each row carries its last message (excerpt only), unread count and next follow-up without N+1.
 */
class ConversationDirectory
{
    /**
     * @param  array{search?: string, status?: string, priority?: string, intent?: string, follow_up?: string, quick?: string}  $filters
     * @return LengthAwarePaginator<int, Conversation>
     */
    public function paginate(Organization $organization, array $filters): LengthAwarePaginator
    {
        $now = $organization->localNow();
        [$start, $end] = [$now->startOfDay()->utc(), $now->endOfDay()->utc()];
        [$prioritySql, $priorityBindings] = AttentionPriorityRules::conversationPrioritySql();

        $query = Conversation::query()
            ->where('conversations.organization_id', $organization->id)
            ->with([
                'customer:id,name,email',
                'latestMessage' => fn ($q) => $q->select('messages.id', 'messages.conversation_id', 'messages.direction', 'messages.status', 'messages.created_at')->selectRaw('left(messages.body_text, 200) as excerpt'),
            ])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'inbound')->whereNull('read_at')])
            ->withMin(['followUps as next_follow_up_at' => fn ($q) => $q->whereIn('status', ['pending', 'due'])], 'due_at');

        match ($filters['quick'] ?? '') {
            'waiting' => $query->waitingForBusiness(),
            'needs_attention' => $query->where('needs_attention', true),
            default => null,
        };

        if ($status = ConversationStatus::tryFrom((string) ($filters['status'] ?? ''))) {
            $query->where('conversations.status', $status);
        }

        if ($priority = AttentionPriority::tryFrom((string) ($filters['priority'] ?? ''))) {
            $query->whereRaw("{$prioritySql} = ?", [...$priorityBindings, $priority->value]);
        }

        $intent = (string) ($filters['intent'] ?? '');
        if ($intent === 'other') {
            $query->whereNotNull('latest_intent')->whereNotIn('latest_intent', array_map(fn ($i) => $i->value, CustomerDirectory::INTENTS));
        } elseif (in_array(CustomerReplyIntent::tryFrom($intent), CustomerDirectory::INTENTS, true)) {
            $query->where('latest_intent', $intent);
        }

        $openFollowUps = fn ($q) => $q->from('follow_ups')->whereColumn('follow_ups.conversation_id', 'conversations.id')->whereIn('follow_ups.status', ['pending', 'due']);
        match ($filters['follow_up'] ?? '') {
            'overdue' => $query->whereExists(fn ($q) => $openFollowUps($q)->where('due_at', '<', $start)),
            'due' => $query->whereExists(fn ($q) => $openFollowUps($q)->whereBetween('due_at', [$start, $end])),
            'scheduled' => $query->whereExists(fn ($q) => $openFollowUps($q)->where('due_at', '>', $end)),
            'none' => $query->whereNotExists($openFollowUps),
            default => null,
        };

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(conversations.subject) like ?', [$like])
                ->orWhereIn('conversations.customer_id', function ($sub) use ($organization, $term) {
                    $sub->from('customers')->select('customers.id')->where('customers.organization_id', $organization->id);
                    app(CustomerDirectory::class)->search((new Customer)->newQuery()->setQuery($sub), $term);
                }));
        }

        return $query
            ->orderByRaw('conversations.last_message_at desc nulls last')
            ->orderByDesc('conversations.id')
            ->paginate(25)
            ->withQueryString();
    }
}

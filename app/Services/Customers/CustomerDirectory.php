<?php

namespace App\Services\Customers;

use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer list: search, filters, sorting and pagination, all in the database and always
 * within one organization.
 */
class CustomerDirectory
{
    public const SORTS = [
        'recent' => 'Recently active',
        'newest' => 'Newest',
        'oldest' => 'Oldest',
        'name_asc' => 'Name A–Z',
        'name_desc' => 'Name Z–A',
    ];

    public const PER_PAGE = [25, 50, 100];

    /**
     * Intents offered as filters; anything else classified counts as "other".
     */
    public const INTENTS = [
        CustomerReplyIntent::Interested,
        CustomerReplyIntent::ReadyToBook,
        CustomerReplyIntent::PriceObjection,
        CustomerReplyIntent::WantsCallback,
        CustomerReplyIntent::Question,
        CustomerReplyIntent::NotInterested,
    ];

    /**
     * @param  array{search?: string, status?: string, conversation?: string, follow_up?: string, intent?: string, sort?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(Organization $organization, array $filters): LengthAwarePaginator
    {
        $perPage = in_array((int) ($filters['per_page'] ?? 25), self::PER_PAGE, true) ? (int) $filters['per_page'] : 25;
        [$start, $end] = $this->today($organization);

        $query = Customer::query()
            ->where('customers.organization_id', $organization->id)
            ->select(['customers.id', 'customers.organization_id', 'customers.name', 'customers.first_name', 'customers.last_name', 'customers.email', 'customers.phone', 'customers.company', 'customers.status', 'customers.last_activity_at', 'customers.created_at'])
            // The most recently active conversation's status and intent, and the next open follow-up.
            ->selectSub(fn ($q) => $q->from('conversations')->whereColumn('conversations.customer_id', 'customers.id')->orderByRaw('last_message_at is null, last_message_at desc')->limit(1)->select('status'), 'conversation_status')
            ->selectSub(fn ($q) => $q->from('conversations')->whereColumn('conversations.customer_id', 'customers.id')->orderByRaw('last_message_at is null, last_message_at desc')->limit(1)->select('latest_intent'), 'latest_intent')
            ->selectSub(fn ($q) => $q->from('follow_ups')->whereColumn('follow_ups.customer_id', 'customers.id')->whereIn('status', ['pending', 'due'])->selectRaw('min(due_at)'), 'next_follow_up_at');

        $this->search($query, (string) ($filters['search'] ?? ''));

        if ($status = CustomerStatus::tryFrom((string) ($filters['status'] ?? ''))) {
            $query->where('customers.status', $status);
        }

        if ($conversationStatus = ConversationStatus::tryFrom((string) ($filters['conversation'] ?? ''))) {
            $query->whereExists(fn ($q) => $q->from('conversations')->whereColumn('conversations.customer_id', 'customers.id')->where('conversations.status', $conversationStatus));
        }

        $intent = (string) ($filters['intent'] ?? '');
        if ($intent === 'other') {
            $query->whereExists(fn ($q) => $q->from('conversations')->whereColumn('conversations.customer_id', 'customers.id')
                ->whereNotNull('latest_intent')->whereNotIn('latest_intent', array_map(fn ($i) => $i->value, self::INTENTS)));
        } elseif (in_array(CustomerReplyIntent::tryFrom($intent), self::INTENTS, true)) {
            $query->whereExists(fn ($q) => $q->from('conversations')->whereColumn('conversations.customer_id', 'customers.id')->where('latest_intent', $intent));
        }

        $openFollowUps = fn ($q) => $q->from('follow_ups')->whereColumn('follow_ups.customer_id', 'customers.id')->whereIn('follow_ups.status', ['pending', 'due']);
        match ($filters['follow_up'] ?? '') {
            'overdue' => $query->whereExists(fn ($q) => $openFollowUps($q)->where('due_at', '<', $start)),
            'due_today' => $query->whereExists(fn ($q) => $openFollowUps($q)->whereBetween('due_at', [$start, $end])),
            'upcoming' => $query->whereExists(fn ($q) => $openFollowUps($q)->where('due_at', '>', $end)),
            'none' => $query->whereNotExists($openFollowUps),
            default => null,
        };

        match ($filters['sort'] ?? 'recent') {
            'newest' => $query->orderByDesc('customers.created_at'),
            'oldest' => $query->orderBy('customers.created_at'),
            'name_asc' => $query->orderBy('customers.last_name')->orderBy('customers.first_name'),
            'name_desc' => $query->orderByDesc('customers.last_name')->orderByDesc('customers.first_name'),
            default => $query->orderByRaw('customers.last_activity_at is null, customers.last_activity_at desc')->orderByDesc('customers.created_at'),
        };

        return $query->orderBy('customers.id')->paginate($perPage)->withQueryString();
    }

    /**
     * Name, email, phone or company "contains" search on the indexed search_text column.
     * Phone searches match digits regardless of formatting.
     *
     * @param  Builder<Customer>  $query
     */
    public function search(Builder $query, string $term): void
    {
        $term = mb_strtolower(trim(preg_replace('/\s+/', ' ', $term)));

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $term);

        $query->where(function ($q) use ($like, $digits, $term) {
            $q->where('customers.search_text', 'like', $like);

            if (strlen($digits) >= 3 && preg_match('/^[\d\s()+.\-]+$/', $term)) {
                $q->orWhere('customers.phone_digits', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function today(Organization $organization): array
    {
        $now = $organization->localNow();

        return [$now->startOfDay()->utc(), $now->endOfDay()->utc()];
    }
}

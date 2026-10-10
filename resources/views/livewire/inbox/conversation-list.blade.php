<div class="space-y-6">
    @php($field = 'block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Conversations</h1>
        <p class="mt-1 text-sm text-gray-600">Customer conversations, most recently active first.</p>
    </div>

    <section aria-labelledby="conversations-heading" class="space-y-3">
        <h2 id="conversations-heading" class="sr-only">Conversations</h2>

        <nav aria-label="Quick filters" class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
            @foreach (\App\Livewire\Inbox\ConversationList::FILTERS as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" @if ($filter === $key) aria-current="true" @endif
                        @class([
                            'shrink-0 whitespace-nowrap rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500',
                            'bg-violet-600 text-white ring-violet-600' => $filter === $key,
                            'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50' => $filter !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        <div class="grid grid-cols-2 gap-2 rounded-lg border border-gray-200 bg-white p-3 shadow-sm sm:grid-cols-5" aria-label="Search and filters">
            <div class="col-span-2 sm:col-span-1"><label for="c-search" class="block text-xs font-medium text-gray-600">Search</label><input id="c-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Customer or subject" class="mt-1 {{ $field }}"></div>
            <div><label for="c-status" class="block text-xs font-medium text-gray-600">Status</label><select id="c-status" wire:model.live="status" class="mt-1 {{ $field }}"><option value="">All</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
            <div><label for="c-priority" class="block text-xs font-medium text-gray-600">Priority</label><select id="c-priority" wire:model.live="priority" class="mt-1 {{ $field }}"><option value="">All</option>@foreach ($priorities as $p)<option value="{{ $p->value }}">{{ ucfirst($p->value) }}</option>@endforeach</select></div>
            <div><label for="c-intent" class="block text-xs font-medium text-gray-600">AI intent</label><select id="c-intent" wire:model.live="intent" class="mt-1 {{ $field }}"><option value="">All</option>@foreach ($intents as $i)<option value="{{ $i->value }}">{{ $i->label() }}</option>@endforeach<option value="other">Other</option></select></div>
            <div><label for="c-follow-up" class="block text-xs font-medium text-gray-600">Follow-up</label><select id="c-follow-up" wire:model.live="followUp" class="mt-1 {{ $field }}"><option value="">All</option><option value="overdue">Overdue</option><option value="due">Due today</option><option value="scheduled">Scheduled</option><option value="none">None</option></select></div>
        </div>

        <div wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($this->conversations->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                    @if (! $filtering)
                        <p class="text-base font-semibold text-gray-900">No conversations yet.</p>
                        <p class="mt-2 text-sm text-gray-600">Conversations appear here when you email a customer or a customer replies.</p>
                    @else
                        <p class="text-base font-semibold text-gray-900">No matching conversations</p>
                        <p class="mt-2 text-sm text-gray-600">No conversation currently matches this filter.</p>
                    @endif
                </div>
            @else
                <ul role="list" class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" data-section="conversations">
                    @foreach ($this->conversations as $conversation)
                        @php($priority = $this->priorityOf($conversation))
                        <li wire:key="conversation-{{ $conversation->id }}">
                            <a href="{{ route('inbox.show', $conversation->id) }}" wire:navigate
                               class="flex flex-col gap-1 px-4 py-3 hover:bg-gray-50 focus:outline-none focus-visible:bg-gray-50 focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-inset sm:flex-row sm:items-start sm:justify-between sm:px-5">
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <span @class(['truncate text-sm text-gray-900', 'font-bold' => $conversation->unread_count, 'font-semibold' => ! $conversation->unread_count])>{{ $conversation->customer->name }}</span>
                                        @if ($conversation->unread_count)
                                            <span class="text-xs font-semibold text-violet-700" data-unread="{{ $conversation->unread_count }}"><span aria-hidden="true">●</span> {{ $conversation->unread_count }} unread</span>
                                        @endif
                                    </p>
                                    <p class="truncate text-sm text-gray-600">{{ $conversation->subject ?? '(no subject)' }}</p>
                                    @if ($conversation->latestMessage?->excerpt)
                                        <p class="truncate text-sm text-gray-500">{{ $conversation->latestMessage->direction->value === 'inbound' ? 'Customer' : 'You' }}: {{ \Illuminate\Support\Str::limit(trim(strtok($conversation->latestMessage->excerpt, "\n")), 140) }}</p>
                                    @endif
                                    <p class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <x-conversation-status-badge :status="$conversation->status" />
                                        @if ($conversation->latest_intent)
                                            <x-intent-badge :intent="$conversation->latest_intent" />
                                            @if ($conversation->latest_confidence !== null)<span class="text-xs text-gray-600">{{ (int) round($conversation->latest_confidence * 100) }}%</span>@endif
                                        @endif
                                        @if ($priority !== \App\Enums\AttentionPriority::Low && ! in_array($conversation->status, [\App\Enums\ConversationStatus::Closed, \App\Enums\ConversationStatus::WaitingCustomer], true))
                                            <x-priority-badge :priority="$priority" />
                                        @endif
                                        @if ($conversation->needs_attention)
                                            <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Needs attention</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="shrink-0 text-xs text-gray-500 sm:text-right">
                                    @if ($conversation->last_message_at)
                                        <p><time datetime="{{ $conversation->last_message_at->toIso8601String() }}">{{ $conversation->last_message_at->diffForHumans() }}</time></p>
                                    @endif
                                    @if ($conversation->next_follow_up_at)
                                        <p>Follow-up {{ $this->organization->localTime(\Carbon\CarbonImmutable::parse($conversation->next_follow_up_at))->format('M j') }}</p>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $this->conversations->links() }}</div>
            @endif
        </div>
    </section>

    @if ($this->needsReview->isNotEmpty())
        <section aria-labelledby="review-heading" class="space-y-3">
            <div>
                <h2 id="review-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Needs review</h2>
                <p class="mt-1 text-sm text-gray-600">These replies used a valid reply address but came from an unexpected sender, so they were not added to a conversation.</p>
            </div>

            <ul role="list" class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-amber-200 bg-amber-50/40">
                @foreach ($this->needsReview as $message)
                    <li wire:key="review-{{ $message->id }}" class="px-5 py-3 text-sm">
                        <p class="font-medium text-gray-900">{{ $message->from_name ? $message->from_name.' <'.$message->from_address.'>' : $message->from_address }}</p>
                        <p class="truncate text-gray-700">{{ $message->subject ?: '(no subject)' }}</p>
                        @if ($message->received_at)
                            <p class="text-xs text-gray-500"><time datetime="{{ $message->received_at->toIso8601String() }}">{{ $message->received_at->diffForHumans() }}</time></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

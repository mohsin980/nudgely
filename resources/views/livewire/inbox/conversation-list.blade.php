<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Inbox</h1>
        <p class="mt-1 text-sm text-gray-600">Customer conversations, including replies to emails QuoteFlow sent.</p>
    </div>

    <section aria-labelledby="conversations-heading" class="space-y-3">
        <h2 id="conversations-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Conversations</h2>

        @if ($this->conversations->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                <p class="text-base font-semibold text-gray-900">No conversations yet</p>
                <p class="mt-2 text-sm text-gray-600">Conversations appear here when QuoteFlow emails a customer.</p>
            </div>
        @else
            <ul role="list" class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                @foreach ($this->conversations as $conversation)
                    <li wire:key="conversation-{{ $conversation->id }}">
                        <a href="{{ route('inbox.show', $conversation->id) }}" wire:navigate
                           class="flex flex-col gap-1 px-5 py-4 hover:bg-gray-50 focus:outline-none focus-visible:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-inset sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900">{{ $conversation->customer->name }}</p>
                                <p class="truncate text-sm text-gray-600">{{ $conversation->subject ?? '(no subject)' }}</p>
                            </div>
                            <p class="shrink-0 text-xs text-gray-500">
                                @if ($conversation->last_message_at)
                                    <time datetime="{{ $conversation->last_message_at->toIso8601String() }}">{{ $conversation->last_message_at->diffForHumans() }}</time>
                                @endif
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
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

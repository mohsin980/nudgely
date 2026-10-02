<div class="space-y-6">
    <div>
        <a href="{{ route('inbox.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Inbox</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">{{ $this->conversation->customer->name }}</h1>
        <p class="text-sm text-gray-600">{{ $this->conversation->subject ?? '(no subject)' }} · {{ $this->conversation->customer->email }}</p>
    </div>

    @if ($this->messages->isEmpty())
        <p class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center text-sm text-gray-600">No messages yet.</p>
    @else
        <ol role="list" class="space-y-4" aria-label="Messages">
            @foreach ($this->messages as $message)
                @php($inbound = $message->isInbound())
                <li wire:key="message-{{ $message->id }}" data-direction="{{ $message->direction->value }}"
                    @class([
                        'rounded-lg p-4 shadow-sm sm:p-5',
                        'mr-0 border border-gray-200 bg-white sm:mr-12' => $inbound,
                        'ml-0 border border-indigo-100 bg-indigo-50 sm:ml-12' => ! $inbound,
                    ])>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="text-sm font-semibold text-gray-900">
                            @if ($inbound)
                                {{ $this->conversation->customer->name }}
                                <span class="font-normal text-gray-500">&lt;{{ $message->from_address }}&gt;</span>
                            @else
                                {{ $message->from_name ?? 'QuoteFlow' }}
                                <span class="font-normal text-gray-500">&lt;{{ $message->from_address }}&gt;</span>
                            @endif
                        </p>
                        <p class="text-xs text-gray-500">
                            <span @class(['font-medium', 'text-gray-700' => $inbound, 'text-indigo-700' => ! $inbound])>{{ $inbound ? 'Received' : 'Sent' }}</span>
                            ·
                            <time datetime="{{ $message->occurredAt()->toIso8601String() }}">{{ $message->occurredAt()->format('M j, Y g:i A') }}</time>
                            @if (! $inbound && $message->status !== \App\Enums\MessageStatus::Sent)
                                · {{ ucfirst($message->status->value) }}
                            @endif
                        </p>
                    </div>

                    @if ($message->subject)
                        <p class="mt-1 text-sm font-medium text-gray-700">{{ $message->subject }}</p>
                    @endif

                    <div class="mt-3 text-sm break-words text-gray-900">
                        @if (filled($message->body_text))
                            {{-- Plain text is always escaped. --}}
                            <div class="whitespace-pre-line">{{ $message->body_text }}</div>
                        @elseif (filled($message->body_html))
                            {{-- Sanitized on arrival and again by safeHtml(); never raw email HTML. --}}
                            <div class="email-html space-y-2 [&_a]:text-indigo-700 [&_a]:underline">{!! $this->safeHtml($message->body_html) !!}</div>
                        @else
                            <p class="text-gray-500 italic">(empty message)</p>
                        @endif
                    </div>

                    @if (! empty($message->metadata['attachments']))
                        <p class="mt-3 text-xs text-gray-500">
                            {{ trans_choice(':count attachment|:count attachments', count($message->metadata['attachments'])) }} not shown. Attachment support is coming soon.
                        </p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>

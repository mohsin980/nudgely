<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('follow-ups.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Follow-Ups</a>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">{{ $this->customer->name }}</h1>
            <p class="text-sm text-gray-600">{{ $this->customer->email }}
                @if ($this->customer->hasOptedOutOfEmail())
                    · <span class="font-medium text-amber-800">Opted out of email</span>
                @endif
            </p>
        </div>
        @unless ($showScheduleForm)
            <button type="button" wire:click="openScheduleForm" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Schedule Follow-Up</button>
        @endunless
    </div>

    @include('follow-ups.flash')

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form')
    @endif

    @php($upcoming = $this->followUps->filter(fn ($f) => $f->status->isOpen()))
    @php($history = $this->followUps->reject(fn ($f) => $f->status->isOpen()))

    <section aria-labelledby="upcoming-heading" class="space-y-2">
        <h2 id="upcoming-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Upcoming follow-ups</h2>
        @if ($upcoming->isEmpty())
            <p class="text-sm text-gray-500">No follow-ups scheduled.</p>
        @else
            <ul role="list" class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white shadow-sm">
                @foreach ($upcoming as $followUp)
                    @include('follow-ups.item', ['showCustomer' => false])
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="history-heading" class="space-y-2">
        <h2 id="history-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Follow-up history</h2>
        @if ($history->isEmpty())
            <p class="text-sm text-gray-500">No past follow-ups.</p>
        @else
            <ol role="list" class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                @foreach ($history as $followUp)
                    <li wire:key="history-{{ $followUp->id }}" class="flex flex-wrap items-start justify-between gap-2 p-4 text-sm">
                        <div>
                            <p class="font-medium text-gray-900">
                                <x-follow-up-due :at="$followUp->created_at" :organization="$organization" /> · {{ $followUp->type->label() }} follow-up
                            </p>
                            <p class="text-gray-700">“{{ $followUp->reason() }}”</p>
                            <p class="text-gray-600">
                                @if ($followUp->skip_reason)
                                    {{ $followUp->skip_reason->label() }}
                                @elseif ($followUp->cancelled_reason)
                                    Cancelled: {{ $followUp->cancelled_reason->label() }}
                                @else
                                    {{ $followUp->outcome ?? ($followUp->completion_notes ?: '') }}
                                @endif
                            </p>
                        </div>
                        <x-automation-status-badge :status="$followUp->status" />
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section aria-labelledby="conversations-heading" class="space-y-2">
        <h2 id="conversations-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Conversations</h2>
        @forelse ($conversations as $conversation)
            <a wire:key="conversation-{{ $conversation->id }}" href="{{ route('inbox.show', $conversation->id) }}" wire:navigate class="block rounded-md border border-gray-200 bg-white px-4 py-3 text-sm hover:bg-gray-50">
                <span class="font-medium text-gray-900">{{ $conversation->subject ?? '(no subject)' }}</span>
                <span class="text-gray-500">· {{ $conversation->status->label() }}</span>
            </a>
        @empty
            <p class="text-sm text-gray-500">No conversations yet.</p>
        @endforelse
    </section>
</div>

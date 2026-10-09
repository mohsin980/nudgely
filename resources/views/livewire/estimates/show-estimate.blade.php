<div class="space-y-6" @if ($this->sending) wire:poll.3s @endif>
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-sm font-semibold tracking-wide text-gray-500 uppercase')
    @php($button = 'inline-flex items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500')
    @php($primary = 'inline-flex items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50')
    @php($estimate = $this->estimate)
    @php($status = $estimate->status)

    {{-- Header --}}
    <div class="space-y-3">
        <a href="{{ route('estimates.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Estimates</a>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-600">{{ $estimate->displayNumber() }}</p>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $estimate->title }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                    <x-estimate-status-badge :status="$status" />
                    @if ($this->sending) <span class="text-blue-700" role="status">Sending…</span> @endif
                    <span>for <a href="{{ route('customers.show', $estimate->customer_id) }}" wire:navigate class="font-medium text-gray-900 hover:underline">{{ $estimate->customer?->name }}</a></span>
                    <span aria-hidden="true">·</span>
                    <span class="text-lg font-semibold text-gray-900" data-total>{{ $estimate->money('total') }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($status === \App\Enums\EstimateStatus::Draft)
                    <a href="{{ route('estimates.edit', $estimate->id) }}" wire:navigate class="{{ $button }}">Edit</a>
                    <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($this->sending) class="{{ $primary }}"><span wire:loading.remove wire:target="send">{{ $this->failedSend ? 'Try Again' : 'Send Estimate' }}</span><span wire:loading wire:target="send">Sending…</span></button>
                @endif
                @if ($status->canBeRevised())
                    <button type="button" wire:click="revise" class="{{ $button }}">Revise Estimate</button>
                @endif
                <button type="button" wire:click="openScheduleForm" class="{{ $button }}">Schedule Follow-Up</button>
                @if (in_array($status, [\App\Enums\EstimateStatus::Draft, \App\Enums\EstimateStatus::Sent, \App\Enums\EstimateStatus::Viewed, \App\Enums\EstimateStatus::Expired], true) && ! $this->sending)
                    <button type="button" wire:click="$set('confirmCancel', true)" class="{{ $button }} text-red-700">Cancel Estimate</button>
                @endif
            </div>
        </div>
    </div>

    @if ($statusMessage)
        <div role="status" @class(['flex items-start justify-between gap-4 rounded-md p-4 text-sm', 'bg-green-50 text-green-800' => $statusMessageType === 'success', 'bg-red-50 text-red-800' => $statusMessageType !== 'success'])>
            <p>{{ $statusMessage }}</p>
            <button type="button" wire:click="$set('statusMessage', null)" class="shrink-0 font-medium hover:underline">Dismiss</button>
        </div>
    @endif

    @if ($this->failedSend && ! $statusMessage)
        <div role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-800">
            <p class="font-medium">Unable to send estimate. Please try again.</p>
            @if ($this->failedSend->failure_reason)<p>{{ $this->failedSend->failure_reason }}</p>@endif
        </div>
    @endif

    @if ($status === \App\Enums\EstimateStatus::Draft && $this->document->items->isEmpty())
        <div role="status" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">Add at least one item before sending this estimate.</div>
    @endif

    @if ($confirmCancel)
        <div class="{{ $card }} space-y-3" role="alertdialog" aria-labelledby="cancel-heading">
            <p id="cancel-heading" class="text-sm text-gray-800">Cancel {{ $estimate->displayNumber() }}? {{ $status === \App\Enums\EstimateStatus::Draft ? 'The draft is kept for your records.' : 'The customer\'s link will stop working. This can\'t be undone.' }}</p>
            <div class="flex gap-2">
                <button type="button" wire:click="cancel" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500">Cancel Estimate</button>
                <button type="button" wire:click="$set('confirmCancel', false)" class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:underline">Keep it</button>
            </div>
        </div>
    @endif

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form')
    @endif
    @include('follow-ups.flash')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- The estimate, as the customer sees it --}}
            <section aria-label="Estimate document" class="{{ $card }} overflow-x-auto">
                @if ($status === \App\Enums\EstimateStatus::Draft)
                    <p class="mb-4 text-xs font-medium tracking-wide text-gray-500 uppercase">Preview — what the customer will see</p>
                @endif
                @include('estimates.document', ['document' => $this->document])
            </section>

            {{-- Activity --}}
            <section aria-labelledby="activity-heading" class="{{ $card }}">
                <h2 id="activity-heading" class="{{ $heading }}">Activity</h2>
                @if ($this->activity->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No activity yet.</p>
                @else
                    <ol role="list" class="mt-2 divide-y divide-gray-100" data-section="activity">
                        @foreach ($this->activity as $entry)
                            @include('livewire.partials.timeline-entry')
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <div class="min-w-0 space-y-6">
            {{-- Customer --}}
            <section aria-labelledby="customer-heading" class="{{ $card }} space-y-1 text-sm">
                <h2 id="customer-heading" class="{{ $heading }}">Customer</h2>
                <p class="pt-1 font-medium text-gray-900">{{ $estimate->customer?->name }}</p>
                <p class="break-words text-gray-600">{{ $estimate->customer?->email }}</p>
                @if ($estimate->customer?->phone)<p class="text-gray-600">{{ $estimate->customer->phone }}</p>@endif
                <div class="flex flex-wrap gap-3 pt-2">
                    <a href="{{ route('customers.show', $estimate->customer_id) }}" wire:navigate class="font-medium text-indigo-700 hover:underline">View Customer</a>
                    @if ($estimate->conversation_id)
                        <a href="{{ route('inbox.show', $estimate->conversation_id) }}" wire:navigate class="font-medium text-indigo-700 hover:underline">View Conversation</a>
                    @endif
                </div>
            </section>

            {{-- Status details --}}
            <section aria-labelledby="status-heading" class="{{ $card }}">
                <h2 id="status-heading" class="{{ $heading }}">Status</h2>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-gray-600">Created</dt><dd class="text-gray-900">{{ $organization->formatDateTime($estimate->created_at) }}</dd>
                    @foreach (['sent_at' => 'Sent', 'viewed_at' => 'Viewed', 'accepted_at' => 'Accepted', 'declined_at' => 'Declined', 'expired_at' => 'Expired', 'cancelled_at' => 'Cancelled'] as $column => $label)
                        @if ($estimate->{$column})
                            <dt class="text-gray-600">{{ $label }}</dt><dd class="text-gray-900">{{ $organization->formatDateTime($estimate->{$column}) }}</dd>
                        @endif
                    @endforeach
                    <dt class="text-gray-600">Valid until</dt><dd class="text-gray-900">{{ $estimate->valid_until ? $organization->formatCalendarDate($estimate->valid_until) : 'No expiry' }}</dd>
                    @if ($estimate->decline_reason || $estimate->decline_note)
                        <dt class="text-gray-600">Reason</dt><dd class="text-gray-900">{{ collect([$estimate->decline_reason?->label(), $estimate->decline_note])->filter()->implode(' — ') }}</dd>
                    @endif
                </dl>
                @if ($estimate->publicUrl() && $status->isAwaitingCustomer())
                    <div class="mt-3 space-y-1">
                        <p class="text-xs font-medium text-gray-600">Customer link</p>
                        <x-copy-button :value="$estimate->publicUrl()" label="Copy link" />
                    </div>
                @endif
            </section>

            {{-- Follow-ups --}}
            <section aria-labelledby="estimate-follow-ups-heading" class="{{ $card }}">
                <h2 id="estimate-follow-ups-heading" class="{{ $heading }}">Follow-ups</h2>
                @if ($this->estimateFollowUps->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No follow-up scheduled for this estimate.</p>
                @else
                    <ul role="list" class="-mx-4 mt-1 divide-y divide-gray-100 sm:-mx-5">
                        @foreach ($this->estimateFollowUps as $followUp)
                            @include('follow-ups.item', ['showCustomer' => false, 'compact' => true])
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Versions --}}
            @if ($this->versions->count() > 1)
                <section aria-labelledby="versions-heading" class="{{ $card }}">
                    <h2 id="versions-heading" class="{{ $heading }}">Versions</h2>
                    <ul role="list" class="mt-2 divide-y divide-gray-100 text-sm" data-section="versions">
                        @foreach ($this->versions as $version)
                            <li wire:key="version-{{ $version->id }}" class="flex items-center justify-between gap-2 py-2">
                                <a href="{{ route('estimates.show', $version->id) }}" wire:navigate @class(['font-medium hover:underline', 'text-gray-900' => $version->id !== $estimate->id, 'text-indigo-700' => $version->id === $estimate->id])>{{ $version->displayNumber() }}</a>
                                <span class="flex items-center gap-2"><span class="text-gray-700">{{ $version->money('total') }}</span><x-estimate-status-badge :status="$version->status" /></span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>

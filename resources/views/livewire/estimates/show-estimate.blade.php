<div class="space-y-6" @if ($this->sending) wire:poll.3s @endif>
    @php($card = 'rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-base font-semibold text-slate-900')
    @php($button = 'inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
    @php($primary = 'inline-flex items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:opacity-50')
    @php($estimate = $this->estimate)
    @php($status = $estimate->status)

    {{-- Header --}}
    <div class="space-y-3">
        <a href="{{ route('estimates.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; Estimates</a>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-500">{{ $estimate->displayNumber() }}</p>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $estimate->title }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                    <x-estimate-status-badge :status="$status" />
                    @if ($this->sending) <span class="text-blue-700" role="status">Sending…</span> @endif
                    <span>for <a href="{{ route('customers.show', $estimate->customer_id) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ $estimate->customer?->name }}</a></span>
                    <span aria-hidden="true">·</span>
                    <span class="text-lg font-semibold text-slate-900" data-total>{{ $estimate->money('total') }}</span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($status === \App\Enums\EstimateStatus::Draft)
                    <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($this->sending) class="{{ $primary }}"><span wire:loading.remove wire:target="send">{{ $this->failedSend ? 'Try Again' : 'Send Estimate' }}</span><span wire:loading wire:target="send">Sending…</span></button>
                    <a href="{{ route('estimates.edit', $estimate->id) }}" wire:navigate class="{{ $button }}">Edit</a>
                    <button type="button" wire:click="openScheduleForm" class="{{ $button }}">Schedule Follow-Up</button>
                    @unless ($this->sending)
                        <details class="relative">
                            <summary aria-label="More actions" class="grid size-9 cursor-pointer list-none place-items-center rounded-lg text-slate-500 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 [&::-webkit-details-marker]:hidden">
                                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="size-5"><path d="M3 10a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm5.5 0a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm5.5 0a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0z"/></svg>
                            </summary>
                            <div class="absolute right-0 z-10 mt-1 w-44 rounded-xl bg-white p-1 shadow-lg ring-1 ring-slate-200">
                                <button type="button" onclick="this.closest('details').open = false" wire:click="$set('confirmCancel', true)" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">Cancel Estimate</button>
                            </div>
                        </details>
                    @endunless
                @else
                    @if ($status->canBeRevised())
                        <button type="button" wire:click="revise" class="{{ $button }}">Revise Estimate</button>
                    @endif
                    <button type="button" wire:click="openScheduleForm" class="{{ $button }}">Schedule Follow-Up</button>
                    @if (in_array($status, [\App\Enums\EstimateStatus::Sent, \App\Enums\EstimateStatus::Viewed, \App\Enums\EstimateStatus::Expired], true) && ! $this->sending)
                        <button type="button" wire:click="$set('confirmCancel', true)" class="{{ $button }} text-red-700">Cancel Estimate</button>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if ($statusMessage)
        <div role="status" @class(['flex items-start justify-between gap-4 rounded-lg p-4 text-sm', 'bg-green-50 text-green-800' => $statusMessageType === 'success', 'bg-red-50 text-red-800' => $statusMessageType !== 'success'])>
            <p>{{ $statusMessage }}</p>
            <button type="button" wire:click="$set('statusMessage', null)" class="shrink-0 font-medium hover:underline">Dismiss</button>
        </div>
    @endif

    @if ($this->failedSend && ! $statusMessage)
        <div role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800">
            <p class="font-medium">Unable to send estimate. Please try again.</p>
            @if ($this->failedSend->failure_reason)<p>{{ $this->failedSend->failure_reason }}</p>@endif
        </div>
    @endif

    @if ($status === \App\Enums\EstimateStatus::Draft && $this->document->items->isEmpty())
        <div role="status" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Add at least one item before sending this estimate.</div>
    @endif

    @if ($confirmCancel)
        <div class="{{ $card }} space-y-3" role="alertdialog" aria-labelledby="cancel-heading">
            <p id="cancel-heading" class="text-sm text-slate-800">Cancel {{ $estimate->displayNumber() }}? {{ $status === \App\Enums\EstimateStatus::Draft ? 'The draft is kept for your records.' : 'The customer\'s link will stop working. This can\'t be undone.' }}</p>
            <div class="flex gap-2">
                <button type="button" wire:click="cancel" class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500">Cancel Estimate</button>
                <button type="button" wire:click="$set('confirmCancel', false)" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:underline">Keep it</button>
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
                    <p class="mb-4 text-xs font-medium text-slate-500">Preview — what the customer will see</p>
                @endif
                @include('estimates.document', ['document' => $this->document])
            </section>

            {{-- Activity --}}
            <section aria-labelledby="activity-heading" class="{{ $card }}">
                <h2 id="activity-heading" class="{{ $heading }}">Activity</h2>
                @if ($this->activity->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">{{ $status === \App\Enums\EstimateStatus::Draft ? 'Sent, viewed and replies appear here after the estimate is sent.' : 'No activity yet.' }}</p>
                @else
                    <ol role="list" class="mt-2 divide-y divide-slate-100" data-section="activity">
                        @foreach ($this->activity as $entry)
                            @include('livewire.partials.timeline-entry')
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <div class="min-w-0 space-y-6">
            @if ($status === \App\Enums\EstimateStatus::Draft)
                <section aria-labelledby="before-sending-heading" class="{{ $card }}">
                    <h2 id="before-sending-heading" class="{{ $heading }}">Before sending</h2>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
                        <li>Add at least one item</li>
                        <li>Check the valid-until date</li>
                        <li>Send when you are ready</li>
                    </ol>
                </section>
            @endif

            {{-- Customer --}}
            <section aria-labelledby="customer-heading" class="{{ $card }} space-y-1 text-sm">
                <h2 id="customer-heading" class="{{ $heading }}">Customer</h2>
                <p class="pt-1 font-medium text-slate-900">{{ $estimate->customer?->name }}</p>
                <p class="break-words text-slate-500">{{ $estimate->customer?->email }}</p>
                @if ($estimate->customer?->phone)<p class="text-slate-500">{{ $estimate->customer->phone }}</p>@endif
                <div class="flex flex-wrap gap-3 pt-2">
                    <a href="{{ route('customers.show', $estimate->customer_id) }}" wire:navigate class="font-medium text-violet-700 hover:underline">View Customer</a>
                    @if ($estimate->conversation_id)
                        <a href="{{ route('inbox.show', $estimate->conversation_id) }}" wire:navigate class="font-medium text-violet-700 hover:underline">View Conversation</a>
                    @endif
                </div>
            </section>

            {{-- Status details --}}
            <section aria-labelledby="status-heading" class="{{ $card }}">
                <h2 id="status-heading" class="{{ $heading }}">Status</h2>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $organization->formatDateTime($estimate->created_at) }}</dd>
                    @foreach (['sent_at' => 'Sent', 'viewed_at' => 'Viewed', 'accepted_at' => 'Accepted', 'declined_at' => 'Declined', 'expired_at' => 'Expired', 'cancelled_at' => 'Cancelled'] as $column => $label)
                        @if ($estimate->{$column})
                            <dt class="text-slate-500">{{ $label }}</dt><dd class="text-slate-900">{{ $organization->formatDateTime($estimate->{$column}) }}</dd>
                        @endif
                    @endforeach
                    <dt class="text-slate-500">Valid until</dt><dd class="text-slate-900">{{ $estimate->valid_until ? $organization->formatCalendarDate($estimate->valid_until) : 'No expiry' }}</dd>
                    @if ($estimate->decline_reason || $estimate->decline_note)
                        <dt class="text-slate-500">Reason</dt><dd class="text-slate-900">{{ collect([$estimate->decline_reason?->label(), $estimate->decline_note])->filter()->implode(' — ') }}</dd>
                    @endif
                </dl>
                @if ($estimate->publicUrl() && $status->isAwaitingCustomer())
                    <div class="mt-3 space-y-1">
                        <p class="text-xs font-medium text-slate-500">Customer link</p>
                        <x-copy-button :value="$estimate->publicUrl()" label="Copy link" />
                    </div>
                @endif
            </section>

            {{-- Follow-ups --}}
            <section aria-labelledby="estimate-follow-ups-heading" class="{{ $card }}">
                <h2 id="estimate-follow-ups-heading" class="{{ $heading }}">Follow-ups</h2>
                @if ($this->estimateFollowUps->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">No follow-up scheduled for this estimate.</p>
                @else
                    <ul role="list" class="-mx-4 mt-1 divide-y divide-slate-100 sm:-mx-5">
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
                    <ul role="list" class="mt-2 divide-y divide-slate-100 text-sm" data-section="versions">
                        @foreach ($this->versions as $version)
                            <li wire:key="version-{{ $version->id }}" class="flex items-center justify-between gap-2 py-2">
                                <a href="{{ route('estimates.show', $version->id) }}" wire:navigate @class(['font-medium hover:underline', 'text-slate-900' => $version->id !== $estimate->id, 'text-violet-700' => $version->id === $estimate->id])>{{ $version->displayNumber() }}</a>
                                <span class="flex items-center gap-2"><span class="text-slate-700">{{ $version->money('total') }}</span><x-estimate-status-badge :status="$version->status" /></span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>

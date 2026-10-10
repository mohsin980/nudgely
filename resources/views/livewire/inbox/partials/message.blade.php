{{-- One message with its AI insight. Expects $message; uses the ShowConversation component helpers. --}}
@php($inbound = $message->isInbound())
<li wire:key="message-{{ $message->id }}" data-direction="{{ $message->direction->value }}"
    @class([
        'rounded-lg p-4 shadow-sm sm:p-5',
        'mr-0 border border-gray-200 bg-white sm:mr-12' => $inbound,
        'ml-0 border border-violet-100 bg-violet-50 sm:ml-12' => ! $inbound,
    ])>
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p class="text-sm font-semibold text-gray-900">
            <span class="mr-1 rounded px-1.5 py-0.5 align-middle text-[11px] font-semibold tracking-wide uppercase ring-1 ring-inset {{ $inbound ? 'bg-white text-gray-700 ring-gray-300' : 'bg-violet-100 text-violet-800 ring-violet-200' }}">{{ $inbound ? 'Customer' : 'Business' }}</span>
            @if ($inbound)
                {{ $this->conversation->customer->name }}
                <span class="font-normal text-gray-500">&lt;{{ $message->from_address }}&gt;</span>
            @else
                {{ $message->from_name ?? 'QuoteFlow' }}
                <span class="font-normal text-gray-500">&lt;{{ $message->from_address }}&gt;</span>
            @endif
        </p>
        <p class="text-xs text-gray-500">
            <span @class(['font-medium', 'text-gray-700' => $inbound, 'text-violet-700' => ! $inbound])>{{ $inbound ? 'Received' : 'Sent' }}</span>
            ·
            <time datetime="{{ $organization->localTime($message->occurredAt())->toIso8601String() }}">{{ $organization->localTime($message->occurredAt())->format('M j, Y g:i A') }}</time>
            @if (! $inbound && $message->status === \App\Enums\MessageStatus::Failed)
                · <span class="font-medium text-red-700" role="status">⚠ Unable to send email{{ $message->failure_reason ? ': '.$message->failure_reason : '' }}</span>
            @elseif (! $inbound && $message->status !== \App\Enums\MessageStatus::Sent)
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
            <div class="email-html space-y-2 [&_a]:text-violet-700 [&_a]:underline">{!! $this->safeHtml($message->body_html) !!}</div>
        @else
            <p class="text-gray-500 italic">(empty message)</p>
        @endif
    </div>

    @if ($inbound)
        @php($succeeded = $message->classifications->where('status', \App\Enums\ClassificationStatus::Succeeded)->values())
        @php($current = $succeeded->first())
        @php($lastAttempt = $message->classifications->first())

        @if ($current)
            @php($level = $current->confidence === null ? null : $this->confidenceLevel($current->confidence))
            <aside aria-label="AI insight" class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase">AI Insight</p>
                    @if ($current->requires_human_review)
                        <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Needs attention</span>
                    @endif
                </div>

                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                    <dt class="text-gray-500">Intent</dt>
                    <dd><x-intent-badge :intent="$current->intent" /></dd>
                    @if ($current->isManual())
                        <dt class="text-gray-500">Set by</dt>
                        <dd class="text-gray-900">{{ $current->overrider?->name ?? 'A team member' }} (was {{ $current->previous_intent?->label() }}){{ $current->override_reason ? ' — '.$current->override_reason : '' }}</dd>
                    @else
                        <dt class="text-gray-500">Confidence</dt>
                        <dd class="text-gray-900">{{ (int) round($current->confidence * 100) }}% <span class="text-gray-500">({{ $level->value }})</span></dd>
                    @endif
                    <dt class="text-gray-500">Summary</dt>
                    <dd class="text-gray-900">{{ $current->summary }}</dd>
                    <dt class="text-gray-500">Needs attention</dt>
                    <dd class="text-gray-900">{{ $current->requires_human_review ? 'Yes' : 'No' }}</dd>
                    @if ($current->sentiment || $current->urgency)
                        <dt class="text-gray-500">Tone</dt>
                        <dd class="text-gray-900">
                            {{ collect([$current->sentiment ? ucfirst($current->sentiment->value).' sentiment' : null, $current->urgency ? ucfirst($current->urgency->value).' urgency' : null])->filter()->implode(' · ') }}
                        </dd>
                    @endif
                </dl>

                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                    <p>{{ $current->isManual() ? 'Corrected by a person.' : 'AI analysis only; the AI itself takes no action. '.$current->model }} · {{ $current->classified_at?->diffForHumans() }}</p>
                    @if ($this->canReclassify())
                        <button type="button" wire:click="reclassify({{ $message->id }})" wire:loading.attr="disabled" wire:target="reclassify({{ $message->id }})"
                                class="rounded-md bg-white px-2 py-1 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 disabled:opacity-50">
                            Reclassify
                        </button>
                    @endif
                </div>

                @if ($lastAttempt && $lastAttempt->id > $current->id && $lastAttempt->status === \App\Enums\ClassificationStatus::Failed)
                    <p class="mt-2 text-xs text-amber-800">The latest reclassification attempt failed; showing the previous result.</p>
                @endif

                @if ($succeeded->count() > 1)
                    <details class="mt-2 text-xs">
                        <summary class="cursor-pointer text-gray-600">Previous classifications ({{ $succeeded->count() - 1 }})</summary>
                        <ul role="list" class="mt-2 space-y-1">
                            @foreach ($succeeded->slice(1) as $previous)
                                <li class="flex flex-wrap items-center gap-2 text-gray-600">
                                    <x-intent-badge :intent="$previous->intent" />
                                    @if ($previous->isManual())
                                        <span>set by {{ $previous->overrider?->name ?? 'a team member' }}</span>
                                    @else
                                        <span>{{ (int) round($previous->confidence * 100) }}%</span>
                                        <span>· {{ $previous->model }}</span>
                                    @endif
                                    <span>· {{ $previous->classified_at ? $organization->localTime($previous->classified_at)->format('M j, Y g:i A') : '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </aside>
        @elseif ($message->status === \App\Enums\MessageStatus::Received)
            <p class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                {{ $lastAttempt ? 'AI insight is unavailable for this reply.' : 'AI insight pending…' }}
                @if ($lastAttempt && $this->canReclassify())
                    <button type="button" wire:click="reclassify({{ $message->id }})" class="font-medium text-violet-700 hover:underline">Try again</button>
                @endif
            </p>
        @endif
    @endif

    @if (! empty($message->metadata['attachments']))
        <p class="mt-3 text-xs text-gray-500">
            {{ trans_choice(':count attachment|:count attachments', count($message->metadata['attachments'])) }} not shown. Attachment support is coming soon.
        </p>
    @endif
</li>

{{-- What the AI assistant did in this conversation: still answering (with "Take over"), or handed over with its summary. --}}
@php($details = collect($chat->details ?? [])->filter())
<section aria-labelledby="assistant-heading" data-assistant-chat="{{ $chat->status }}"
    @class(['rounded-2xl p-4 ring-1 sm:p-5', 'bg-violet-50/70 ring-violet-200' => $chat->isActive(), 'bg-amber-50/70 ring-amber-200' => ! $chat->isActive()])>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <span @class(['grid size-10 shrink-0 place-items-center rounded-xl', 'bg-violet-100 text-violet-600' => $chat->isActive(), 'bg-amber-100 text-amber-700' => ! $chat->isActive()])><x-ui-icon name="sparkles" class="size-5" /></span>
            <div class="min-w-0">
                <h2 id="assistant-heading" class="font-bold text-slate-900">
                    @if ($chat->isActive())
                        {{ $assistantName }} (AI assistant) is answering this chat
                    @elseif ($chat->taken_over_by)
                        {{ $chat->takenOverBy?->name ?? 'A team member' }} took over from {{ $assistantName }}
                    @else
                        {{ $assistantName }} handed this chat over to you
                    @endif
                </h2>
                @if (! $chat->isActive() && ! $chat->taken_over_by && $chat->handover_reason)
                    <p class="text-sm font-semibold text-amber-900">{{ $chat->handover_reason }}</p>
                @endif
                @if ($chat->summary)
                    <p class="mt-1 text-sm text-slate-700" data-assistant-summary>{{ $chat->summary }}</p>
                @endif
                @if ($details->isNotEmpty() || $chat->qualified_at)
                    <dl class="mt-2 flex flex-wrap gap-2 text-xs">
                        @if ($chat->qualified_at)
                            <div class="rounded-full bg-teal-50 px-2.5 py-1 font-semibold text-teal-700 ring-1 ring-teal-600/20 ring-inset"><dt class="sr-only">Lead</dt><dd>Qualified</dd></div>
                        @endif
                        @foreach ($details as $field => $value)
                            <div class="rounded-full bg-white px-2.5 py-1 text-slate-700 ring-1 ring-slate-200 ring-inset"><dt class="inline font-semibold">{{ ucfirst($field) }}:</dt> <dd class="inline">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>
        @if ($chat->isActive())
            <button type="button" wire:click="takeOverFromAssistant" class="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-xl bg-white px-4 py-2 text-sm font-semibold text-violet-700 shadow-xs ring-1 ring-violet-200 ring-inset hover:bg-violet-50">Take over</button>
        @endif
    </div>
</section>

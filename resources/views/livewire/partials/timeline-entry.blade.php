{{-- One timeline entry. Expects $entry (TimelineEntry) and $organization. --}}
@php($kindLabel = ['customer' => 'Customer', 'business' => 'Business', 'system' => 'System', 'automation' => 'Automation'][$entry->kind] ?? 'Activity')
@php($kindClass = ['customer' => 'bg-white text-gray-700 ring-gray-300', 'business' => 'bg-violet-50 text-violet-700 ring-violet-200', 'system' => 'bg-gray-50 text-gray-600 ring-gray-200', 'automation' => 'bg-violet-50 text-violet-700 ring-violet-200'][$entry->kind] ?? 'bg-gray-50 text-gray-600 ring-gray-200')
@php($local = $organization->localTime($entry->at))
<li class="flex gap-3 py-3 text-sm" data-kind="{{ $entry->kind }}">
    <time class="w-24 shrink-0 text-xs text-gray-500 sm:w-28" datetime="{{ $local->toIso8601String() }}">{{ $local->format('M j — g:i A') }}</time>
    <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-center gap-2">
            <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold tracking-wide uppercase ring-1 ring-inset {{ $kindClass }}">{{ $kindLabel }}</span>
            <span class="font-medium text-gray-900">{{ $entry->title }}</span>
        </p>
        @if ($entry->body)
            <p class="mt-0.5 break-words text-gray-700">{{ $entry->kind === 'customer' || $entry->kind === 'business' ? '“'.$entry->body.'”' : $entry->body }}</p>
        @endif
        @if ($entry->details)
            <ul role="list" class="mt-1 space-y-0.5 text-gray-700">
                @foreach ($entry->details as $detail)
                    <li><span aria-hidden="true">{{ $detail['ok'] ? '✓' : '–' }}</span> <span class="sr-only">{{ $detail['status'] }}:</span> {{ $detail['text'] }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</li>

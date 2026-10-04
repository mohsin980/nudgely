{{-- Who changed what, when. Expects $history (OrganizationActivity collection) and $organization. --}}
<section aria-labelledby="history-heading" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <h2 id="history-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Change history</h2>
    @if ($history->isEmpty())
        <p class="mt-2 text-sm text-gray-600">No changes yet.</p>
    @else
        <ul role="list" class="mt-2 divide-y divide-gray-100 text-sm" data-section="history">
            @foreach ($history as $entry)
                <li class="py-2" wire:key="history-{{ $entry->id }}">
                    <p class="text-gray-900"><span class="font-medium">{{ $entry->user?->name ?? 'System' }}</span> · {{ $entry->label() }}@if ($entry->subject) · {{ $entry->subject->name }}@endif</p>
                    @foreach ($entry->data['changes'] ?? [] as $field => $change)
                        <p class="text-gray-600">{{ $field }}: {{ ($change['from'] ?? null) === null || $change['from'] === '' ? '—' : $change['from'] }} → {{ ($change['to'] ?? null) === null || $change['to'] === '' ? '—' : $change['to'] }}</p>
                    @endforeach
                    @if (isset($entry->data['from'], $entry->data['to']) && $entry->action === 'role_changed')
                        <p class="text-gray-600">{{ ucfirst($entry->data['from']) }} → {{ ucfirst($entry->data['to']) }}</p>
                    @endif
                    <p class="text-xs text-gray-500">{{ $organization->formatDateTime($entry->created_at) }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</section>

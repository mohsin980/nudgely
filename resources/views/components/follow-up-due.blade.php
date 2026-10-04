@props(['at', 'organization'])

@php
    // Stored in UTC; shown in the organization's timezone and formats, e.g. "Today — 2:00 PM" or "10/06/2026 — 11:00 AM".
    $local = $organization->localTime($at);
    $today = $organization->localNow()->startOfDay();
    $day = $local->startOfDay();
    $label = match (true) {
        $day->equalTo($today) => 'Today',
        $day->equalTo($today->subDay()) => 'Yesterday',
        $day->equalTo($today->addDay()) => 'Tomorrow',
        $day->year === $today->year && $organization->dateFormat() === 'M j, Y' => $local->format('M j'),
        default => $organization->formatDate($at),
    };
@endphp

<time {{ $attributes }} datetime="{{ $local->toIso8601String() }}" title="{{ $local->format('M j, Y g:i A T') }}">{{ $label }} — {{ $organization->formatTime($at) }}</time>

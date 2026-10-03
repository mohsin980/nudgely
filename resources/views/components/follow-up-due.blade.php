@props(['at', 'organization'])

@php
    // Stored in UTC; shown in the organization's timezone, e.g. "Today — 2:00 PM" or "Oct 6 — 11:00 AM".
    $local = $organization->localTime($at);
    $today = $organization->localNow()->startOfDay();
    $day = $local->startOfDay();
    $label = match (true) {
        $day->equalTo($today) => 'Today',
        $day->equalTo($today->subDay()) => 'Yesterday',
        $day->equalTo($today->addDay()) => 'Tomorrow',
        $day->year === $today->year => $local->format('M j'),
        default => $local->format('M j, Y'),
    };
@endphp

<time {{ $attributes }} datetime="{{ $local->toIso8601String() }}" title="{{ $local->format('M j, Y g:i A T') }}">{{ $label }} — {{ $local->format('g:i A') }}</time>

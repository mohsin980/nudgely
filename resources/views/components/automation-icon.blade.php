{{-- A line icon for an automation or a starter template. Unknown names fall back to the bolt. --}}
@props(['name' => 'bolt'])
@php($paths = [
    'bolt' => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
    'check' => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
    'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
    'play' => '<circle cx="12" cy="12" r="9"/><path d="M10 8l6 4-6 4z"/>',
    'bell' => '<path d="M18 16V11a6 6 0 10-12 0v5l-2 2h16z"/><path d="M10 20a2 2 0 004 0"/>',
    'refresh' => '<path d="M20 11a8 8 0 00-14.3-4.6L4 8"/><path d="M4 3v5h5"/><path d="M4 13a8 8 0 0014.3 4.6L20 16"/><path d="M20 21v-5h-5"/>',
    'x' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
])
<svg {{ $attributes->class(['size-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$name] ?? $paths['bolt'] !!}</svg>

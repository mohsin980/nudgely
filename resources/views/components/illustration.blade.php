@props(['name', 'class' => 'size-20'])

{{-- Decorative, glossy illustrations drawn inline (no image requests). The page text carries the meaning. --}}
@php
    $id = 'il'.substr(md5($name.uniqid('', true)), 0, 6);
    $badges = [
        'calendar-alert' => ['#fb7185', '#e11d48'],
        'calendar-clock' => ['#a78bfa', '#6d28d9'],
        'calendar-plus' => ['#34d399', '#059669'],
        'calendar-check' => ['#a78bfa', '#7c3aed'],
    ];
    $viewBox = $name === 'hero' ? '0 0 340 120' : '0 0 96 96';
@endphp

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="{{ $viewBox }}" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="{{ $id }}v" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a78bfa"/><stop offset="1" stop-color="#6d28d9"/></linearGradient>
        <linearGradient id="{{ $id }}w" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#ede9fe"/></linearGradient>
        <linearGradient id="{{ $id }}s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".75"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
        @isset($badges[$name])
            <linearGradient id="{{ $id }}b" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $badges[$name][0] }}"/><stop offset="1" stop-color="{{ $badges[$name][1] }}"/></linearGradient>
        @endisset
        <filter id="{{ $id }}f" x="-30%" y="-30%" width="160%" height="170%"><feDropShadow dx="0" dy="5" stdDeviation="4.5" flood-color="#5b21b6" flood-opacity=".22"/></filter>
    </defs>

    @if (isset($badges[$name]))
        <g filter="url(#{{ $id }}f)">
            <rect x="10" y="16" width="62" height="58" rx="11" fill="url(#{{ $id }}w)"/>
            <path d="M10 27a11 11 0 0 1 11-11h40a11 11 0 0 1 11 11v5H10v-5Z" fill="url(#{{ $id }}v)"/>
            <rect x="22" y="10" width="6" height="14" rx="3" fill="#7c3aed"/><rect x="54" y="10" width="6" height="14" rx="3" fill="#7c3aed"/>
            @foreach ([[18, 39], [31, 39], [44, 39], [57, 39], [18, 52], [31, 52], [44, 52], [18, 63], [31, 63]] as [$x, $y])
                <rect x="{{ $x }}" y="{{ $y }}" width="8" height="7" rx="2" fill="#c4b5fd" opacity=".85"/>
            @endforeach
            <circle cx="68" cy="66" r="18" fill="url(#{{ $id }}b)"/>
            <circle cx="68" cy="66" r="18" fill="none" stroke="#fff" stroke-width="3.5"/>
            @if ($name === 'calendar-check')
                <path d="m61 66 5 5 9-10" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            @elseif ($name === 'calendar-plus')
                <path d="M68 58v16M60 66h16" stroke="#fff" stroke-width="4" stroke-linecap="round"/>
            @else
                <path d="M68 57v10l6 4" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
            @endif
            <ellipse cx="63" cy="57" rx="9" ry="4.5" fill="url(#{{ $id }}s)"/>
        </g>
    @elseif ($name === 'chats')
        <g filter="url(#{{ $id }}f)">
            <path d="M14 22a10 10 0 0 1 10-10h34a10 10 0 0 1 10 10v20a10 10 0 0 1-10 10H32l-12 9v-9.6A10 10 0 0 1 14 42V22Z" fill="url(#{{ $id }}w)" stroke="#ddd6fe" stroke-width="1.5"/>
            <rect x="24" y="24" width="32" height="5" rx="2.5" fill="#c4b5fd"/><rect x="24" y="35" width="22" height="5" rx="2.5" fill="#ddd6fe"/>
            <path d="M40 46a10 10 0 0 1 10-10h26a10 10 0 0 1 10 10v16a10 10 0 0 1-10 10v8l-11-8H50a10 10 0 0 1-10-10V46Z" fill="url(#{{ $id }}v)"/>
            <circle cx="53" cy="54" r="3.2" fill="#fff"/><circle cx="63" cy="54" r="3.2" fill="#fff"/><circle cx="73" cy="54" r="3.2" fill="#fff"/>
        </g>
    @elseif ($name === 'hero')
        <defs>
            <linearGradient id="{{ $id }}g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4ade80"/><stop offset="1" stop-color="#16a34a"/></linearGradient>
            <linearGradient id="{{ $id }}k" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#2563eb"/></linearGradient>
        </defs>
        <path d="M6 66c30 4 40-6 60-4s40 14 70 0 40-40 80-30 40 22 70 20 40-10 48-12" stroke="#fff" stroke-width="2.5" stroke-dasharray="4 6" stroke-linecap="round" opacity=".9"/>
        <g filter="url(#{{ $id }}f)">
            <g transform="rotate(-8 70 62)"><rect x="44" y="36" width="54" height="50" rx="14" fill="url(#{{ $id }}w)"/><rect x="55" y="49" width="32" height="23" rx="4" fill="url(#{{ $id }}v)"/><path d="m56 51 15 11 15-11" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></g>
            <circle cx="160" cy="34" r="25" fill="url(#{{ $id }}g)"/><path d="M146 52l3-10" stroke="#16a34a" stroke-width="7" stroke-linecap="round"/>
            <circle cx="151" cy="34" r="3" fill="#fff"/><circle cx="160" cy="34" r="3" fill="#fff"/><circle cx="169" cy="34" r="3" fill="#fff"/>
            <path d="M206 50a14 14 0 0 1 14-14h20a14 14 0 0 1 14 14v8a14 14 0 0 1-14 14h-14l-10 8v-9a14 14 0 0 1-10-13v-8Z" fill="url(#{{ $id }}k)"/>
            <path d="M224 47v-2a6 6 0 0 1 12 0v2l2 8h-16l2-8Zm4 11h4" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <rect x="272" y="30" width="54" height="54" rx="16" fill="url(#{{ $id }}w)"/>
            <rect x="284" y="46" width="30" height="24" rx="9" fill="url(#{{ $id }}v)"/><rect x="297" y="38" width="4" height="8" rx="2" fill="#7c3aed"/>
            <circle cx="293" cy="57" r="3.4" fill="#fff"/><circle cx="305" cy="57" r="3.4" fill="#fff"/><rect x="279" y="53" width="5" height="9" rx="2.5" fill="#7c3aed"/><rect x="314" y="53" width="5" height="9" rx="2.5" fill="#7c3aed"/>
        </g>
        <path d="M322 22l6-6M330 32l8-2" stroke="#ec4899" stroke-width="3" stroke-linecap="round"/>
    @elseif ($name === 'check')
        <g filter="url(#{{ $id }}f)">
            <circle cx="48" cy="48" r="28" fill="url(#{{ $id }}v)" opacity=".85"/>
            <circle cx="48" cy="48" r="28" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="2"/>
            <path d="m36 49 8 8 16-17" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>
            <ellipse cx="42" cy="31" rx="14" ry="6" fill="url(#{{ $id }}s)"/>
        </g>
    @elseif ($name === 'bell')
        <g filter="url(#{{ $id }}f)">
            <path d="M48 16c-13 0-22 10-22 23v13l-6 10h56l-6-10V39c0-13-9-23-22-23Z" fill="url(#{{ $id }}v)"/>
            <circle cx="48" cy="70" r="7" fill="#7c3aed"/>
            <rect x="44" y="10" width="8" height="8" rx="4" fill="#8b5cf6"/>
            <path d="M36 26c3-4 7-6 12-6" stroke="#fff" stroke-opacity=".7" stroke-width="4" stroke-linecap="round"/>
        </g>
        <path d="M76 18l6-6M80 28l9-2M70 12l2-8" stroke="#ec4899" stroke-width="3.5" stroke-linecap="round"/>
    @elseif ($name === 'rocket')
        <g filter="url(#{{ $id }}f)" transform="rotate(40 48 48)">
            <path d="M48 8c11 9 15 22 15 36v18H33V44c0-14 4-27 15-36Z" fill="url(#{{ $id }}v)"/>
            <path d="M33 50 22 64l11 2v-16Zm30 0 11 14-11 2V50Z" fill="#6d28d9"/>
            <circle cx="48" cy="36" r="7" fill="#fff"/><circle cx="48" cy="36" r="4" fill="#1e1b4b"/>
            <path d="M40 66h16l-4 14h-8l-4-14Z" fill="#f472b6"/><path d="M44 66h8l-2 9h-4l-2-9Z" fill="#fde68a"/>
            <path d="M41 20c2-4 4-7 7-9" stroke="#fff" stroke-opacity=".7" stroke-width="3" stroke-linecap="round"/>
        </g>
    @elseif ($name === 'plane')
        <path d="M8 74c10 4 18-8 26-4s4 12 12 8 6-14 16-16" stroke="#a78bfa" stroke-width="2.5" stroke-dasharray="3 5" stroke-linecap="round"/>
        <g filter="url(#{{ $id }}f)">
            <path d="M88 14 30 40l20 8 38-34Z" fill="url(#{{ $id }}v)"/>
            <path d="M88 14 50 48l6 22 32-56Z" fill="#7c3aed"/>
            <path d="m50 48 2 18 4 4-6-22Z" fill="#5b21b6"/>
        </g>
    @endif
</svg>

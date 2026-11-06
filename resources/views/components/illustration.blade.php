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
    $viewBox = match ($name) { 'hero' => '0 0 340 120', 'store', 'automation' => '0 0 300 150', default => '0 0 96 96' };
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
    @elseif ($name === 'shield')
        <g filter="url(#{{ $id }}f)">
            <path d="M48 8 78 19v24c0 20-13 35-30 43C31 78 18 63 18 43V19L48 8Z" fill="url(#{{ $id }}v)"/>
            <path d="M48 15 71 24v19c0 16-10 28-23 35-13-7-23-19-23-35V24l23-9Z" fill="#fff" opacity=".22"/>
            <rect x="35" y="44" width="26" height="21" rx="5" fill="url(#{{ $id }}w)"/>
            <path d="M40 44v-5a8 8 0 0 1 16 0v5" stroke="#ede9fe" stroke-width="4.5" fill="none"/>
            <circle cx="48" cy="53" r="3.2" fill="#6d28d9"/><rect x="46.6" y="54" width="2.8" height="6" rx="1.4" fill="#6d28d9"/>
            <path d="M30 26c4-3 9-5 14-6" stroke="#fff" stroke-opacity=".75" stroke-width="3.5" stroke-linecap="round"/>
        </g>
        <circle cx="84" cy="14" r="5" fill="#c4b5fd"/><circle cx="12" cy="40" r="3.5" fill="#ddd6fe"/><circle cx="86" cy="62" r="3" fill="#a78bfa"/>
    @elseif ($name === 'robot')
        <g filter="url(#{{ $id }}f)">
            <rect x="45" y="8" width="6" height="12" rx="3" fill="#8b5cf6"/><circle cx="48" cy="8" r="5" fill="url(#{{ $id }}v)"/>
            <rect x="20" y="18" width="56" height="44" rx="18" fill="url(#{{ $id }}w)"/>
            <rect x="27" y="27" width="42" height="26" rx="12" fill="#3b0764"/>
            <circle cx="39" cy="40" r="5" fill="#a78bfa"/><circle cx="57" cy="40" r="5" fill="#a78bfa"/>
            <circle cx="40.5" cy="38.5" r="1.6" fill="#fff"/><circle cx="58.5" cy="38.5" r="1.6" fill="#fff"/>
            <rect x="12" y="34" width="9" height="16" rx="4.5" fill="#8b5cf6"/><rect x="75" y="34" width="9" height="16" rx="4.5" fill="#8b5cf6"/>
            <path d="M30 66h36a8 8 0 0 1 8 8v10H22V74a8 8 0 0 1 8-8Z" fill="url(#{{ $id }}v)"/>
            <circle cx="48" cy="75" r="4" fill="#ede9fe"/>
            <path d="M28 24c4-3 9-4 14-4" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
        </g>
        <path d="M80 12l5-5M84 22l8-1" stroke="#ec4899" stroke-width="3" stroke-linecap="round"/>
    @elseif ($name === 'store')
        <ellipse cx="190" cy="140" rx="120" ry="8" fill="#ede9fe"/>
        <path d="M60 140c0-40 30-62 70-62 10-30 60-42 90-18 36-6 66 20 66 60v20H60Z" fill="#f3f0ff"/>
        <circle cx="76" cy="24" r="8" fill="#f1edff"/><circle cx="88" cy="22" r="11" fill="#f1edff"/><circle cx="262" cy="26" r="9" fill="#f1edff"/><circle cx="276" cy="24" r="12" fill="#f1edff"/>
        <g filter="url(#{{ $id }}f)">
            <rect x="148" y="56" width="104" height="84" rx="4" fill="url(#{{ $id }}w)"/>
            <path d="M140 24h120l14 34H126l14-34Z" fill="url(#{{ $id }}v)"/>
            @foreach ([126, 150, 174, 198, 222, 246] as $i => $x)
                <path d="M{{ $x }} 58a12 12 0 0 0 24 0Z" fill="{{ $i % 2 ? '#ffffff' : '#7c3aed' }}" stroke="#6d28d9" stroke-width="1.5"/>
            @endforeach
            <rect x="140" y="18" width="120" height="10" rx="5" fill="#6d28d9"/>
            <rect x="160" y="84" width="30" height="56" rx="3" fill="#5b21b6"/><rect x="164" y="88" width="22" height="24" rx="2" fill="#a78bfa"/>
            <rect x="204" y="84" width="36" height="28" rx="3" fill="#7c3aed"/><rect x="208" y="88" width="28" height="20" rx="2" fill="#c4b5fd"/>
        </g>
        <g fill="#a78bfa"><path d="M106 140c-4-26 4-46 14-56 6 16 6 38-2 56Z"/><path d="M98 140c-10-14-14-30-10-42 10 8 16 24 16 42Z" opacity=".7"/></g>
        <rect x="112" y="122" width="16" height="18" rx="3" fill="#8b5cf6"/>
        <g fill="#a78bfa"><path d="M270 140c4-24-2-44-12-54-6 16-6 36 2 54Z"/><path d="M280 140c10-14 12-28 8-40-10 8-14 22-14 40Z" opacity=".7"/></g>
        <path d="M58 140V96m-10 44V108m20 32v-26" stroke="#c4b5fd" stroke-width="3" stroke-linecap="round"/>
    @elseif ($name === 'automation')
        <path d="M70 120c40-50 160-70 220-20v50H40c0-12 10-22 30-30Z" fill="#f3f0ff"/>
        <path d="M96 62h40M188 48h40M200 108h-28" stroke="#c4b5fd" stroke-width="2" stroke-dasharray="3 4"/>
        <g filter="url(#{{ $id }}f)">
            <rect x="44" y="40" width="50" height="46" rx="12" fill="url(#{{ $id }}w)"/><rect x="54" y="52" width="30" height="22" rx="4" fill="url(#{{ $id }}v)"/><path d="m55 54 14 10 14-10" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round"/>
            <rect x="238" y="18" width="46" height="44" rx="12" fill="url(#{{ $id }}w)"/><rect x="248" y="30" width="26" height="22" rx="4" fill="#7c3aed"/><path d="M248 37h26" stroke="#fff" stroke-width="2"/><g fill="#fff"><rect x="252" y="41" width="4" height="4" rx="1"/><rect x="259" y="41" width="4" height="4" rx="1"/><rect x="266" y="41" width="4" height="4" rx="1"/></g>
            <rect x="250" y="92" width="38" height="36" rx="11" fill="url(#{{ $id }}w)"/><circle cx="269" cy="110" r="10" fill="#7c3aed"/><path d="m264 110 4 4 7-8" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round"/>
            <rect x="160" y="10" width="4" height="16" rx="2" fill="#8b5cf6"/><circle cx="162" cy="9" r="5" fill="url(#{{ $id }}v)"/>
            <rect x="130" y="24" width="64" height="48" rx="22" fill="url(#{{ $id }}w)"/>
            <rect x="139" y="33" width="46" height="30" rx="14" fill="#2e1065"/>
            <path d="M150 50q4-5 8 0M166 50q4-5 8 0" stroke="#a78bfa" stroke-width="3" fill="none" stroke-linecap="round"/>
            <path d="M136 80h52a10 10 0 0 1 10 10v40h-72V90a10 10 0 0 1 10-10Z" fill="url(#{{ $id }}v)"/>
            <circle cx="162" cy="100" r="6" fill="#ede9fe"/>
            <g transform="translate(212 92)"><circle r="16" fill="#8b5cf6"/><circle r="6" fill="#ede9fe"/>@foreach (range(0, 315, 45) as $deg)<rect x="-3" y="-21" width="6" height="8" rx="2" fill="#8b5cf6" transform="rotate({{ $deg }})"/>@endforeach</g>
        </g>
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

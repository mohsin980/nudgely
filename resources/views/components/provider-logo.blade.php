@props(['name', 'class' => 'size-8'])
{{-- Small marks for the email services a business can connect. Decorative: the name is always written next to it. --}}
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 48 48" aria-hidden="true" focusable="false">
    @if ($name === 'gmail')
        <path fill="#4285F4" d="M6 40h7V22.6L4 15.9V37a3 3 0 0 0 2 3Z"/>
        <path fill="#34A853" d="M35 40h7a3 3 0 0 0 2-3V15.9l-9 6.7V40Z"/>
        <path fill="#FBBC04" d="M35 11v11.6l9-6.7V12.3c0-3.6-4.1-5.6-6.9-3.4L35 11Z"/>
        <path fill="#EA4335" d="M13 22.6V11l11 8.3L35 11v11.6l-11 8.2-11-8.2Z"/>
        <path fill="#C5221F" d="M4 12.3v3.6l9 6.7V11l-2.1-2.1C8.1 6.7 4 8.7 4 12.3Z"/>
    @elseif ($name === 'microsoft')
        <rect x="16" y="8" width="28" height="32" rx="3" fill="#1490DF"/>
        <path fill="#28A8EA" d="M16 8h14v11H16z"/><path fill="#0078D4" d="M30 8h14v11H30z"/><path fill="#50D9FF" d="M30 19h14v10H30z"/>
        <rect x="4" y="13" width="24" height="22" rx="3" fill="#0A64AD"/>
        <ellipse cx="16" cy="24" rx="6" ry="7" fill="none" stroke="#fff" stroke-width="3"/>
    @else
        <rect x="6" y="10" width="36" height="28" rx="6" fill="#ede9fe"/>
        <path d="m9 15 15 11 15-11" fill="none" stroke="#7c3aed" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    @endif
</svg>

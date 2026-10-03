@props(['priority'])

{{-- Text and a symbol, not just color. --}}
@php
    $classes = match ($priority) {
        \App\Enums\AttentionPriority::High => 'bg-red-50 text-red-700 ring-red-600/20',
        \App\Enums\AttentionPriority::Medium => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        \App\Enums\AttentionPriority::Low => 'bg-gray-50 text-gray-600 ring-gray-500/20',
    };
    $symbol = match ($priority) {
        \App\Enums\AttentionPriority::High => '▲',
        \App\Enums\AttentionPriority::Medium => '■',
        \App\Enums\AttentionPriority::Low => '▽',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }} data-priority="{{ $priority->value }}">
    <span aria-hidden="true">{{ $symbol }}</span>{{ $priority->label() }}
</span>

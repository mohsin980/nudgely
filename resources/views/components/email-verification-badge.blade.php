@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\EmailVerificationStatus::Verified => 'bg-green-50 text-green-700 ring-green-600/20',
        \App\Enums\EmailVerificationStatus::Failed => 'bg-red-50 text-red-700 ring-red-600/20',
        default => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    {{ $status->label() }}
</span>

@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\ConversationStatus::Open => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        \App\Enums\ConversationStatus::WaitingBusiness => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        \App\Enums\ConversationStatus::WaitingCustomer => 'bg-slate-50 text-slate-700 ring-gray-500/20',
        \App\Enums\ConversationStatus::Closed => 'bg-slate-100 text-slate-500 ring-gray-500/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-lg px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>{{ $status->label() }}</span>

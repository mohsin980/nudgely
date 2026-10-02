@props(['intent'])

@php
    // Labels and colors come from the enum, never from AI text.
    $classes = match ($intent) {
        \App\Enums\CustomerReplyIntent::ReadyToBook => 'bg-green-50 text-green-700 ring-green-600/20',
        \App\Enums\CustomerReplyIntent::Interested => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        \App\Enums\CustomerReplyIntent::PriceObjection, \App\Enums\CustomerReplyIntent::WantsCallback => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        \App\Enums\CustomerReplyIntent::Question, \App\Enums\CustomerReplyIntent::NeedsMoreInformation => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        \App\Enums\CustomerReplyIntent::Complaint => 'bg-red-50 text-red-700 ring-red-600/20',
        default => 'bg-gray-50 text-gray-700 ring-gray-500/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    {{ $intent->label() }}
</span>

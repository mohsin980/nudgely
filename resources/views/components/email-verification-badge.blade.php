@props(['status'])

@php
    $verified = $status === \App\Enums\EmailVerificationStatus::Verified;
    $failed = $status === \App\Enums\EmailVerificationStatus::Failed;
    $classes = match (true) {
        $verified => 'bg-green-50 text-green-700 ring-green-600/20',
        $failed => 'bg-red-50 text-red-700 ring-red-600/20',
        default => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    };
@endphp

{{-- The text carries the meaning; the icon is only a second cue (never colour alone). --}}
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    @if ($verified)
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
    @elseif ($failed)
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9 6h2v5H9V6zm0 6.5h2v2H9v-2z" clip-rule="evenodd"/></svg>
    @else
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4.2l2.9 1.7a1 1 0 001-1.7L11 9.4V6z" clip-rule="evenodd"/></svg>
    @endif
    {{ $status->label() }}
</span>

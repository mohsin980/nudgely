{{-- The owner's billing banner: payment problem, restriction, trial ending, cancellation scheduled or plan ended. Nothing for healthy subscriptions. --}}
@props(['banner'])
<div @class([
    'border-b',
    'border-red-200 bg-red-50 text-red-900' => $banner['tone'] === 'danger',
    'border-amber-200 bg-amber-50 text-amber-900' => $banner['tone'] === 'warning',
    'border-indigo-100 bg-indigo-50 text-indigo-900' => $banner['tone'] === 'info',
]) data-billing-banner="{{ $banner['kind'] }}" role="{{ $banner['tone'] === 'info' ? 'status' : 'alert' }}">
    <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-2 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-6">
        <p>{{ $banner['message'] }}</p>
        <a href="{{ $banner['url'] }}" @if (! str_contains($banner['url'], 'payment-method')) wire:navigate @endif class="shrink-0 font-semibold underline">{{ $banner['action'] }}</a>
    </div>
</div>

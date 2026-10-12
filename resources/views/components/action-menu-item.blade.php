@props(['disabled' => false, 'reason' => null, 'danger' => false, 'itemId'])
@php
    // A disabled entry never carries its click handler, so it cannot fire even if the markup is edited in the browser;
    // the server checks the same rule again.
    $bag = $attributes->except($disabled ? ['wire:click'] : []);
@endphp
{{-- One menu entry. A disabled entry stays in the menu, is announced as disabled, and always shows why. --}}
<button type="button" role="menuitem"
        @if ($disabled) aria-disabled="true" aria-describedby="{{ $itemId }}-reason" @endif
        {{ $bag->class([
            'block w-full px-4 py-2 text-left text-sm focus:outline-none focus-visible:bg-violet-50',
            'cursor-not-allowed text-gray-400' => $disabled,
            'text-red-700 hover:bg-red-50' => ! $disabled && $danger,
            'text-gray-700 hover:bg-gray-50' => ! $disabled && ! $danger,
        ]) }}>
    <span class="block font-medium">{{ $slot }}</span>
    @if ($disabled && $reason)
        <span id="{{ $itemId }}-reason" class="mt-0.5 block text-xs font-normal text-gray-500">{{ $reason }}</span>
    @endif
</button>

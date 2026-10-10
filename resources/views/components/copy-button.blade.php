@props(['value', 'label'])

<button type="button"
        x-data="{ copied: false }"
        x-on:click="navigator.clipboard?.writeText(@js($value)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
        {{ $attributes->class('shrink-0 rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500') }}>
    <span x-show="! copied">Copy</span>
    <span x-show="copied" x-cloak>Copied</span>
    <span class="sr-only">{{ $label }}</span>
</button>

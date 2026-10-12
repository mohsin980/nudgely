@props(['label'])
{{-- A small "more actions" menu: opens with Enter/Space, closes with Escape or an outside click, arrow keys move between items. --}}
<div x-data="{ open: false }"
     x-init="$watch('open', value => { if (value) $nextTick(() => $refs.menu.querySelector('[role=menuitem]')?.focus()) })"
     x-on:keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }"
     x-on:click.outside="open = false"
     class="relative">
    <button type="button"
            x-ref="trigger"
            x-on:click="open = ! open"
            aria-haspopup="menu"
            x-bind:aria-expanded="open.toString()"
            aria-label="{{ $label }}"
            class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-white text-gray-600 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 5.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM11.5 15.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z"/></svg>
    </button>

    <div x-show="open" x-cloak x-ref="menu"
         x-on:keydown.arrow-down.prevent="$focus.wrap().next()"
         x-on:keydown.arrow-up.prevent="$focus.wrap().previous()"
         x-on:click="if ($event.target.closest('[role=menuitem]:not([aria-disabled=true])')) open = false"
         role="menu" aria-label="{{ $label }}"
         class="absolute right-0 z-20 mt-2 w-72 max-w-[calc(100vw-2rem)] origin-top-right divide-y divide-gray-100 rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5">
        {{ $slot }}
    </div>
</div>

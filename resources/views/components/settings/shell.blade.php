@props(['title', 'description' => null])
{{-- Settings page frame: a grouped menu (sidebar on desktop, scrolling chips on phones), then the page. --}}
@php($menu = \App\Support\Settings\SettingsNavigation::for(auth()->user()))
<div class="space-y-6 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-10 lg:space-y-0">

    {{-- Phones and tablets: every page as one horizontal row of chips --}}
    <nav aria-label="Settings" class="-mx-4 overflow-x-auto px-4 lg:hidden" x-data x-init="$el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' })">
        <ul role="list" class="flex w-max gap-2 pb-1">
            @foreach ($menu as $group)
                @foreach ($group['items'] as $item)
                    @php($active = request()->routeIs($item['route'], $item['route'].'.*'))
                    <li>
                        <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium ring-1 ring-inset transition',
                               'bg-violet-600 text-white ring-violet-600' => $active,
                               'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => ! $active,
                           ])>{{ $item['label'] }}</a>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </nav>

    {{-- Desktop: grouped sidebar --}}
    <nav aria-label="Settings" class="hidden lg:sticky lg:top-6 lg:block">
        <div class="space-y-6 rounded-2xl border border-slate-200 bg-white p-3 shadow-xs">
            @foreach ($menu as $group)
                <div>
                    <p class="flex items-center gap-2 px-3 pt-1 pb-2 text-sm font-semibold text-slate-500">
                        <x-ui-icon :name="$group['icon']" class="size-4 text-slate-400" />
                        {{ $group['label'] }}
                    </p>
                    <ul role="list" class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            @php($active = request()->routeIs($item['route'], $item['route'].'.*'))
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                                   @class([
                                       'block rounded-xl py-2 pr-3 pl-10 text-[15px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500',
                                       'bg-violet-50 font-semibold text-violet-800' => $active,
                                       'text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! $active,
                                   ])>{{ $item['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </nav>

    <div class="min-w-0 space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 text-[15px] text-slate-500">{{ $description }}</p>
            @endif
        </div>
        {{ $slot }}
    </div>
</div>

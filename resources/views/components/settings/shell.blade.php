@props(['title', 'description' => null, 'art' => null, 'bare' => false])
{{-- Settings page frame: a grouped menu (sidebar on desktop, scrolling chips on phones), then the page. --}}
@php($menu = \App\Support\Settings\SettingsNavigation::for(auth()->user()))
@php($icons = [
    'settings.business' => 'building', 'settings.preferences' => 'cog', 'settings.team' => 'users', 'settings.roles' => 'shield',
    'settings.email' => 'envelope', 'settings.whatsapp' => 'chat', 'settings.notifications' => 'bell',
    'settings.estimates' => 'document', 'settings.follow-ups' => 'clock', 'settings.automation' => 'bolt',
    'settings.security' => 'lock', 'settings.billing' => 'dollar',
])
@php($current = collect($menu)->pluck('items')->flatten(1)->first(fn ($item) => request()->routeIs($item['route'], $item['route'].'.*')))
{{-- $bare: a full-width page with a "Back to Settings" link instead of the settings menu. --}}
<div @class(['space-y-6', 'lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-8 lg:space-y-0' => ! $bare])>
    @unless ($bare)

    {{-- Phones and tablets: every page as one horizontal row of chips --}}
    <nav aria-label="Settings" class="-mx-4 overflow-x-auto px-4 lg:hidden" x-data x-init="$el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' })">
        <ul role="list" class="flex w-max gap-2 pb-1">
            @foreach ($menu as $group)
                @foreach ($group['items'] as $item)
                    @php($active = request()->routeIs($item['route'], $item['route'].'.*'))
                    <li>
                        <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                           @class([
                               'inline-flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-inset transition',
                               'bg-violet-600 text-white ring-violet-600 shadow-[0_8px_18px_-8px_rgba(109,40,217,0.8)]' => $active,
                               'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50' => ! $active,
                           ])><x-ui-icon :name="$icons[$item['route']] ?? 'cog'" class="size-4" />{{ $item['label'] }}</a>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </nav>

    {{-- Desktop: grouped sidebar --}}
    <nav aria-label="Settings" class="hidden lg:sticky lg:top-6 lg:block">
        <div class="space-y-5 rounded-2xl border border-slate-200/80 bg-white p-3 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)]">
            @foreach ($menu as $group)
                <div>
                    <p class="px-3 pt-1 pb-2 text-[11px] font-bold tracking-[0.08em] whitespace-nowrap text-slate-400 uppercase">{{ $group['label'] }}</p>
                    <ul role="list" class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            @php($active = request()->routeIs($item['route'], $item['route'].'.*'))
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                                   @class([
                                       'group flex items-center gap-3 rounded-xl px-3 py-2 text-[15px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500',
                                       'bg-violet-50 font-semibold text-violet-800 ring-1 ring-violet-100 ring-inset' => $active,
                                       'text-slate-700 hover:bg-slate-50 hover:text-slate-900' => ! $active,
                                   ])>
                                    <span @class([
                                        'grid size-8 shrink-0 place-items-center rounded-lg transition',
                                        'bg-violet-600 text-white shadow-[0_6px_14px_-6px_rgba(109,40,217,0.8)]' => $active,
                                        'bg-slate-100 text-slate-500 group-hover:bg-violet-50 group-hover:text-violet-600' => ! $active,
                                    ])><x-ui-icon :name="$icons[$item['route']] ?? 'cog'" class="size-[18px]" /></span>
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </nav>

    @endunless

    <div class="min-w-0 space-y-6">
        @if ($bare)
            <a href="{{ route('settings.index') }}" wire:navigate class="-mb-3 inline-flex items-center gap-2 text-[15px] font-semibold text-violet-700 hover:text-violet-800"><x-ui-icon name="arrow-left" class="size-5 stroke-2" /> Back to Settings</a>
        @endif
        <header class="flex items-start gap-4">
            @unless ($art)
                <span aria-hidden="true" class="grid size-16 shrink-0 place-items-center rounded-2xl bg-linear-to-b from-violet-100 to-violet-200/60 text-violet-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.9)]"><x-ui-icon name="cog" class="size-8 stroke-[1.8]" /></span>
            @endunless
            <div class="min-w-0 flex-1">
                <h1 @class(['font-bold tracking-tight text-slate-900', 'text-[28px] leading-tight' => ! $art, 'text-3xl font-extrabold sm:text-[34px]' => $art])>{{ $title }}</h1>
                @if ($description)
                    <p class="mt-1 text-[15px] text-slate-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
            @if ($art)
                <x-illustration :name="$art" @class(['hidden shrink-0 sm:block', '-my-4 size-28' => ! $bare, '-mt-14 -mb-6 size-36' => $bare]) />
            @endif
        </header>
        {{ $slot }}
    </div>
</div>

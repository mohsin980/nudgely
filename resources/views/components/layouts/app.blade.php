<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFollow') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full bg-slate-50 font-sans text-slate-900 antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow">
            Skip to content
        </a>

        @php
            $user = auth()->user();
            $organization = $user?->organization;
            $badges = $organization ? app(\App\Support\NavBadges::class)->for($organization) : ['messages' => 0, 'reminders' => 0];
            // Plain words on purpose: what a business owner would say, not product jargon.
            $nav = array_values(array_filter([
                ['Home', 'dashboard', 'dashboard', 'home', 0],
                ['Customers', 'customers.index', 'customers.*', 'users', 0],
                ['Messages', 'inbox.index', 'inbox.*', 'chat', $badges['messages']],
                ['Quotes', 'estimates.index', 'estimates.*', 'document', 0],
                ['Reminders', 'follow-ups.index', 'follow-ups.*', 'clock', $badges['reminders']],
                $user && $user->can('viewAny', \App\Models\Automation::class) ? ['Automations', 'automations.index', 'automations.*', 'bolt', 0] : null,
            ]));
            $link = fn (bool $active) => 'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium whitespace-nowrap transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 '
                .($active ? 'bg-violet-100/70 text-violet-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900');
            $icon = fn (bool $active) => 'size-5 shrink-0 '.($active ? 'text-violet-600' : 'text-slate-400 group-hover:text-slate-600');
            $initials = $user ? collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') : '';
            $tabs = ['dashboard', 'customers.index', 'inbox.index', 'estimates.index'];
        @endphp

        <div x-data="{ open: false }" @keydown.escape.window="open = false">
            {{-- Sidebar (drawer on small screens) --}}
            <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/40 lg:hidden" @click="open = false" aria-hidden="true"></div>

            <aside :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
                   class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:z-30">
                <div class="flex h-16 shrink-0 items-center justify-between px-5">
                    <a href="{{ url('/') }}" class="flex items-center gap-2.5 text-base font-semibold tracking-tight text-slate-900">
                        <span aria-hidden="true" class="grid size-8 place-items-center rounded-lg bg-linear-to-br from-violet-700 to-violet-400 text-sm font-bold text-white shadow-sm">Q</span>
                        {{ config('app.name', 'QuoteFollow') }}
                    </a>
                    <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden" @click="open = false">
                        <span class="sr-only">Close menu</span>
                        <x-ui-icon name="close" />
                    </button>
                </div>

                @auth
                    <nav aria-label="Main" class="flex-1 overflow-y-auto px-3 py-2">
                        <ul class="space-y-1">
                            @foreach ($nav as [$label, $route, $pattern, $ico, $count])
                                @php($active = request()->routeIs($pattern))
                                <li>
                                    <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif class="{{ $link($active) }}">
                                        <x-ui-icon :name="$ico" class="{{ $icon($active) }}" />
                                        {{ $label }}
                                        @if ($count > 0)
                                            <span class="ml-auto grid min-w-5 place-items-center rounded-full bg-red-500 px-1.5 text-xs font-semibold text-white">{{ $count > 99 ? '99+' : $count }}<span class="sr-only"> waiting</span></span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>

                        <p class="mt-6 mb-2 px-3 text-xs font-semiboldr text-slate-400">More</p>
                        @php($active = request()->routeIs('settings.*'))
                        <a href="{{ route('settings.index') }}" @if ($active) aria-current="page" @endif class="{{ $link($active) }}">
                            <x-ui-icon name="cog" class="{{ $icon($active) }}" />
                            Settings
                        </a>
                    </nav>

                    <div class="border-t border-slate-200 p-3">
                        <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                            <span aria-hidden="true" class="grid size-9 shrink-0 place-items-center rounded-full bg-violet-100 text-sm font-semibold text-violet-700">{{ $initials }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $user->name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $organization?->name }}</p>
                            </div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500" title="Sign out">
                                    <span class="sr-only">Sign out</span>
                                    <x-ui-icon name="logout" />
                                </button>
                            </form>
                        </div>
                    </div>
                @endauth
            </aside>

            <div class="lg:pl-64">
                {{-- Mobile top bar --}}
                <header class="sticky top-0 z-20 flex h-14 items-center gap-2.5 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
                    <span aria-hidden="true" class="grid size-8 place-items-center rounded-lg bg-linear-to-br from-violet-700 to-violet-400 text-sm font-bold text-white shadow-sm">Q</span>
                    <span class="text-base font-semibold text-slate-900">{{ config('app.name', 'QuoteFollow') }}</span>
                </header>

                @auth
                    @can('manage-billing')
                        @php($billingBanner = request()->routeIs('settings.billing*') ? null : app(\App\Services\Billing\BillingBanner::class)->for($organization))
                        @if ($billingBanner)
                            <x-billing.banner :banner="$billingBanner" />
                        @endif
                    @endcan
                @endauth

                <main id="main" class="mx-auto min-w-0 max-w-6xl px-4 pt-6 pb-28 sm:px-6 lg:px-8 lg:py-10">
                    {{ $slot }}
                </main>
            </div>

            {{-- Mobile bottom tabs: the four things people do most, plus More --}}
            @auth
                <nav aria-label="Quick" class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
                    <ul class="mx-auto grid max-w-md grid-cols-5">
                        @foreach ($nav as [$label, $route, $pattern, $ico, $count])
                            @continue(! in_array($route, $tabs, true))
                            @php($active = request()->routeIs($pattern))
                            <li>
                                <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif class="relative flex flex-col items-center gap-0.5 py-2 text-xs font-medium {{ $active ? 'text-violet-700' : 'text-slate-500' }}">
                                    <x-ui-icon :name="$ico" class="size-6" />
                                    {{ $label }}
                                    @if ($count > 0)
                                        <span class="absolute top-1 left-1/2 ml-2 grid min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">{{ $count > 99 ? '99+' : $count }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                        <li>
                            <button type="button" @click="open = true" class="flex w-full flex-col items-center gap-0.5 py-2 text-xs font-medium text-slate-500">
                                <x-ui-icon name="menu" class="size-6" />
                                More
                            </button>
                        </li>
                    </ul>
                </nav>
            @endauth
        </div>
    </body>
</html>

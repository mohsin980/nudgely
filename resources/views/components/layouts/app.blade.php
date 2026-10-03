<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFlow') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow">
            Skip to content
        </a>

        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
                <a href="{{ url('/') }}" class="text-base font-semibold tracking-tight text-gray-900">
                    {{ config('app.name', 'QuoteFlow') }}
                </a>

                @auth
                    <span class="truncate text-sm text-gray-600">{{ auth()->user()->organization?->name }}</span>
                @endauth
            </div>
        </header>

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:grid lg:grid-cols-[13rem_1fr] lg:gap-10 lg:py-10">
            <nav aria-label="Main" class="mb-6 lg:mb-0">
                <ul class="flex gap-1 overflow-x-auto lg:flex-col">
                    <li>
                        <a href="{{ route('inbox.index') }}"
                           @if (request()->routeIs('inbox.*')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('inbox.*'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('inbox.*'),
                           ])>
                            Inbox
                        </a>
                    </li>
                    <li class="hidden px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase lg:block" aria-hidden="true">Settings</li>
                    <li>
                        <a href="{{ route('settings.email') }}"
                           @if (request()->routeIs('settings.email')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('settings.email'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('settings.email'),
                           ])>
                            Email
                        </a>
                    </li>
                    @can('viewAny', \App\Models\Automation::class)
                        <li>
                            <a href="{{ route('settings.automations.index') }}"
                               @if (request()->routeIs('settings.automations.*')) aria-current="page" @endif
                               @class([
                                   'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                                   'bg-indigo-50 text-indigo-700' => request()->routeIs('settings.automations.*'),
                                   'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('settings.automations.*'),
                               ])>
                                Automations
                            </a>
                        </li>
                    @endcan
                </ul>
            </nav>

            <main id="main" class="min-w-0">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>

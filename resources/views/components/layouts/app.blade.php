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
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="truncate text-sm text-gray-600">{{ auth()->user()->organization?->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm font-medium text-gray-700 hover:underline">Sign out</button>
                        </form>
                    </div>
                @endauth
            </div>
        </header>

        @auth
            @can('manage-billing')
                @php($billingBanner = request()->routeIs('settings.billing*') ? null : app(\App\Services\Billing\BillingBanner::class)->for(auth()->user()->organization))
                @if ($billingBanner)
                    <x-billing.banner :banner="$billingBanner" />
                @endif
            @endcan
        @endauth

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:grid lg:grid-cols-[13rem_1fr] lg:gap-10 lg:py-10">
            <nav aria-label="Main" class="mb-6 lg:mb-0">
                <ul class="flex gap-1 overflow-x-auto lg:flex-col">
                    <li>
                        <a href="{{ route('dashboard') }}"
                           @if (request()->routeIs('dashboard')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('dashboard'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('dashboard'),
                           ])>
                            Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('follow-ups.index') }}"
                           @if (request()->routeIs('follow-ups.*')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('follow-ups.*'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('follow-ups.*'),
                           ])>
                            Follow-Ups
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('customers.index') }}"
                           @if (request()->routeIs('customers.*')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('customers.*'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('customers.*'),
                           ])>
                            Customers
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('inbox.index') }}"
                           @if (request()->routeIs('inbox.*')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('inbox.*'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('inbox.*'),
                           ])>
                            Conversations
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('estimates.index') }}"
                           @if (request()->routeIs('estimates.*')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('estimates.*'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('estimates.*'),
                           ])>
                            Estimates
                        </a>
                    </li>
                    @can('viewAny', \App\Models\Automation::class)
                        <li>
                            <a href="{{ route('automations.index') }}"
                               @if (request()->routeIs('automations.*')) aria-current="page" @endif
                               @class([
                                   'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
                                   'bg-indigo-50 text-indigo-700' => request()->routeIs('automations.*'),
                                   'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('automations.*'),
                               ])>
                                Automations
                            </a>
                        </li>
                    @endcan
                    <li>
                        <a href="{{ route('settings.index') }}"
                           @if (request()->routeIs('settings.*')) aria-current="page" @endif
                           @class([
                               'block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 lg:mt-4',
                               'bg-indigo-50 text-indigo-700' => request()->routeIs('settings.*'),
                               'text-gray-700 hover:bg-gray-100 hover:text-gray-900' => ! request()->routeIs('settings.*'),
                           ])>
                            Settings
                        </a>
                    </li>

                </ul>
            </nav>

            <main id="main" class="min-w-0">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>

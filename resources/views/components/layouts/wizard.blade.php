<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFollow') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-sans text-gray-900 antialiased">
        <header class="border-b border-gray-200 bg-white shadow-sm">
            <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4 sm:px-6">
                <a href="{{ url('/') }}" class="flex items-center gap-2 text-base font-semibold tracking-tight text-gray-900">
                    <span aria-hidden="true" class="grid size-7 place-items-center rounded-md bg-linear-to-br from-violet-600 to-emerald-500 text-sm font-bold text-white">Q</span>
                    {{ config('app.name', 'QuoteFollow') }}
                </a>
                <div class="flex items-center gap-4 text-sm">
                    @can('manage-billing')<a href="{{ route('settings.billing') }}" class="font-medium text-gray-700 hover:underline">Billing</a>@endcan
                    <a href="{{ route('settings.security') }}" class="font-medium text-gray-700 hover:underline">Account</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="font-medium text-gray-700 hover:underline">Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        @auth
            @can('manage-billing')
                @php($billingBanner = app(\App\Services\Billing\BillingBanner::class)->for(auth()->user()->organization))
                @if ($billingBanner)
                    <x-billing.banner :banner="$billingBanner" />
                @endif
            @endcan
        @endauth

        <main id="main" class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:py-10">
            {{ $slot }}
        </main>
    </body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-[#f7f6fc]">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFollow') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full bg-[#f7f6fc] font-sans text-slate-900 antialiased">
        {{-- Soft lavender shapes behind the page. --}}
        <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-0 overflow-hidden">
            <div class="absolute -top-40 right-[-10rem] size-[40rem] rounded-full bg-violet-200/40 blur-3xl"></div>
            <div class="absolute -bottom-48 right-[-6rem] size-[30rem] rounded-full bg-violet-100/70 blur-3xl"></div>
            <div class="absolute -bottom-40 -left-40 size-[28rem] rounded-full bg-fuchsia-100/50 blur-3xl"></div>
        </div>

        <div class="relative">
            @auth
                @can('manage-billing')
                    @php($billingBanner = app(\App\Services\Billing\BillingBanner::class)->for(auth()->user()->organization))
                    @if ($billingBanner)
                        <x-billing.banner :banner="$billingBanner" />
                    @endif
                @endcan
            @endauth

            <main id="main">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>

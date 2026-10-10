<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-white">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFollow') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased">
        <div class="grid min-h-full lg:grid-cols-2">
            <aside class="relative hidden overflow-hidden bg-linear-to-br from-violet-700 via-violet-500 to-emerald-500 p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <div class="flex items-center gap-3">
                    <span aria-hidden="true" class="grid size-9 place-items-center rounded-lg bg-white text-base font-bold text-violet-700">Q</span>
                    <span class="text-lg font-semibold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                </div>

                <div class="max-w-md">
                    <h2 class="text-4xl leading-tight font-semibold tracking-tight">Every quote gets a follow-up.</h2>
                    <p class="mt-4 text-base leading-relaxed text-violet-100">Send estimates, follow up on time, and see the replies that matter first.</p>
                    <ul class="mt-8 space-y-3 text-sm text-violet-50">
                        <li class="flex items-center gap-3"><span class="grid size-5 place-items-center rounded-full bg-yellow-300 text-xs font-bold text-violet-900">✓</span> Automatic follow-ups that stop when a customer replies</li>
                        <li class="flex items-center gap-3"><span class="grid size-5 place-items-center rounded-full bg-yellow-300 text-xs font-bold text-violet-900">✓</span> One inbox, sorted by what needs you first</li>
                    </ul>
                </div>

                <p class="text-sm text-violet-100/80">Built for trades and service businesses</p>
                <span aria-hidden="true" class="pointer-events-none absolute -right-24 -bottom-24 size-96 rounded-full bg-yellow-300/25 blur-3xl"></span>
            </aside>

            <main id="main" class="flex items-center justify-center px-6 py-12 sm:px-12">
                <div class="w-full max-w-sm">
                    <div class="mb-8 flex items-center gap-2 lg:hidden">
                        <span aria-hidden="true" class="grid size-8 place-items-center rounded-lg bg-violet-600 text-sm font-bold text-white">Q</span>
                        <span class="text-base font-semibold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                    </div>
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>

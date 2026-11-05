<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-white">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'QuoteFollow') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-slate-900 antialiased">
        <div class="grid min-h-full lg:grid-cols-2">
            <aside class="relative hidden overflow-hidden bg-linear-to-br from-violet-700 via-violet-600 to-violet-800 p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <svg aria-hidden="true" class="pointer-events-none absolute inset-0 size-full text-white/10" viewBox="0 0 800 900" preserveAspectRatio="none">
                    <path d="M0 620 C 220 520, 380 760, 800 560 L 800 900 L 0 900 Z" fill="currentColor" />
                    <path d="M0 240 C 260 80, 520 300, 800 120 L 800 0 L 0 0 Z" fill="currentColor" />
                </svg>

                <div class="relative flex items-center gap-3">
                    <span aria-hidden="true" class="grid size-11 place-items-center rounded-xl bg-white text-xl font-bold text-violet-700 shadow-lg shadow-violet-900/20">Q</span>
                    <span class="text-xl font-semibold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                </div>

                <div class="relative max-w-lg">
                    <h2 class="text-5xl leading-[1.1] font-semibold tracking-tight">Every quote gets a <span class="text-violet-200">follow-up.</span></h2>
                    <p class="mt-5 text-lg leading-relaxed text-violet-100">Send estimates, follow up on time, and see the replies that matter most.</p>

                    <ul class="mt-10 space-y-6">
                        <li class="flex items-start gap-4">
                            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-white/15 ring-1 ring-white/20 ring-inset"><x-ui-icon name="clock" class="size-6" /></span>
                            <div>
                                <p class="font-semibold">Automatic follow-ups</p>
                                <p class="text-sm text-violet-100">that stop when a customer replies.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-4">
                            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-white/15 ring-1 ring-white/20 ring-inset"><x-ui-icon name="chat" class="size-6" /></span>
                            <div>
                                <p class="font-semibold">One inbox</p>
                                <p class="text-sm text-violet-100">sorted by what needs you first.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-4">
                            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-white/15 ring-1 ring-white/20 ring-inset"><x-ui-icon name="bolt" class="size-6" /></span>
                            <div>
                                <p class="font-semibold">Automations</p>
                                <p class="text-sm text-violet-100">save time and grow your business.</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <p class="relative flex items-center gap-2 text-sm text-violet-100">
                    <x-ui-icon name="check" class="size-5 text-violet-200" />
                    Built for trades and service businesses
                </p>
            </aside>

            <main id="main" class="flex items-center justify-center px-6 py-12 sm:px-12">
                <div class="w-full max-w-md">
                    <div class="mb-10 flex items-center justify-center gap-2 lg:hidden">
                        <span aria-hidden="true" class="grid size-9 place-items-center rounded-lg bg-violet-600 text-sm font-bold text-white">Q</span>
                        <span class="text-base font-semibold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                    </div>
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>

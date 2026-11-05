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
            <aside class="relative hidden overflow-hidden bg-linear-to-br from-violet-700 via-violet-600 to-indigo-800 p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <svg aria-hidden="true" class="pointer-events-none absolute inset-0 size-full text-white/10" viewBox="0 0 800 900" preserveAspectRatio="none">
                    <path d="M0 620 C 220 520, 380 760, 800 560 L 800 900 L 0 900 Z" fill="currentColor" />
                    <path d="M0 240 C 260 80, 520 300, 800 120 L 800 0 L 0 0 Z" fill="currentColor" />
                </svg>

                <div class="relative flex items-center gap-3">
                    <span aria-hidden="true" class="grid size-12 place-items-center rounded-xl bg-white text-2xl font-bold text-violet-700 shadow-lg shadow-violet-900/20">Q</span>
                    <span class="text-2xl font-semibold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                </div>

                <div class="relative">
                    <svg aria-hidden="true" class="pointer-events-none ml-auto mb-6 hidden h-56 w-56 xl:block" viewBox="0 0 280 280" fill="none">
                        <rect x="60" y="10" width="170" height="70" rx="14" fill="white" fill-opacity="0.9" />
                        <rect x="78" y="30" width="30" height="30" rx="8" fill="#7C3AED" />
                        <path d="M84 39 L93 46 L102 39" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <rect x="120" y="32" width="90" height="8" rx="4" fill="#DDD6FE" />
                        <rect x="120" y="48" width="60" height="8" rx="4" fill="#DDD6FE" />
                        <path d="M170 82 C 190 100, 200 110, 205 120" stroke="white" stroke-opacity="0.7" stroke-width="2" stroke-dasharray="4 5" fill="none" />
                        <circle cx="210" cy="140" r="24" fill="#22C55E" />
                        <path d="M202 133 q3 9 10 12 l3-3 -3-3 -2 2 q-3-2-4-5 l2-2 -3-3 z" fill="white" />
                        <path d="M205 164 C 200 180, 190 190, 175 200" stroke="white" stroke-opacity="0.7" stroke-width="2" stroke-dasharray="4 5" fill="none" />
                        <circle cx="150" cy="212" r="22" fill="#3B82F6" />
                        <text x="150" y="216" text-anchor="middle" font-size="10" font-weight="700" fill="white" font-family="sans-serif">SMS</text>
                        <path d="M130 226 C 120 236, 110 240, 100 242" stroke="white" stroke-opacity="0.7" stroke-width="2" stroke-dasharray="4 5" fill="none" />
                        <circle cx="72" cy="240" r="24" fill="#C4B5FD" />
                        <path d="M76 226 L66 244 H74 L70 256 L80 238 H72 Z" fill="#7C3AED" />
                    </svg>

                    <h2 class="text-6xl leading-[1.05] font-bold tracking-tight">Every quote gets a</h2>
                    <h2 class="relative mt-1 inline-block text-6xl leading-[1.05] font-bold tracking-tight">
                        <span class="text-violet-200">follow-up.</span>
                        <svg aria-hidden="true" class="absolute -bottom-3 left-0 w-full" viewBox="0 0 300 12" preserveAspectRatio="none" fill="none">
                            <path d="M4 8 C 90 2, 200 2, 296 6" stroke="#DDD6FE" stroke-width="4" stroke-linecap="round" />
                        </svg>
                    </h2>
                    <p class="mt-8 max-w-md text-xl leading-relaxed text-violet-100">Send estimates, follow up on time, and see the replies that matter most.</p>

                    <ul class="mt-12 grid grid-cols-3 gap-6">
                        <li>
                            <span class="grid size-14 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/20 ring-inset"><x-ui-icon name="clock" class="size-7" /></span>
                            <p class="mt-4 text-lg font-semibold leading-snug">Automatic follow-ups</p>
                            <p class="mt-1 text-sm leading-relaxed text-violet-100">that stop when a customer replies.</p>
                        </li>
                        <li>
                            <span class="grid size-14 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/20 ring-inset"><x-ui-icon name="chat" class="size-7" /></span>
                            <p class="mt-4 text-lg font-semibold leading-snug">WhatsApp &amp; SMS</p>
                            <p class="mt-1 text-sm leading-relaxed text-violet-100">reach customers on their preferred channel.</p>
                        </li>
                        <li>
                            <span class="grid size-14 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/20 ring-inset"><x-ui-icon name="bolt" class="size-7" /></span>
                            <p class="mt-4 text-lg font-semibold leading-snug">Powerful Automation</p>
                            <p class="mt-1 text-sm leading-relaxed text-violet-100">save time and grow your business.</p>
                        </li>
                    </ul>
                </div>

                <p class="relative flex items-center gap-3 text-base text-violet-100">
                    <x-ui-icon name="shield" class="size-6 text-violet-200" />
                    Built for trades and service businesses
                </p>
            </aside>

            <main id="main" class="relative flex items-start justify-center overflow-hidden px-6 pt-12 pb-12 sm:px-12 lg:pt-24">
                <span aria-hidden="true" class="pointer-events-none absolute -top-24 -right-24 size-80 rounded-full bg-violet-100/70"></span>
                <span aria-hidden="true" class="pointer-events-none absolute -right-20 -bottom-28 size-72 rounded-full bg-violet-100/60"></span>
                <div class="relative w-full max-w-md">
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

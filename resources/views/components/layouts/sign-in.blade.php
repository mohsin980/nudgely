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
            <aside class="relative hidden min-h-screen overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between" style="background: radial-gradient(55% 45% at 12% 8%, rgba(255,255,255,0.45) 0%, transparent 70%), radial-gradient(60% 50% at 92% 96%, #c026d3 0%, transparent 65%), linear-gradient(155deg, #a855f7 0%, #7c3aed 30%, #5b21ff 65%, #3b0fd0 100%);">
                <span aria-hidden="true" class="pointer-events-none absolute -top-24 -left-24 size-96 rounded-full bg-white/25 blur-3xl"></span>
                <span aria-hidden="true" class="pointer-events-none absolute top-1/3 -right-40 h-40 w-[140%] -rotate-12 bg-gradient-to-r from-transparent via-white/15 to-transparent blur-2xl"></span>
                <svg aria-hidden="true" class="pointer-events-none absolute inset-0 size-full text-white/10" viewBox="0 0 800 900" preserveAspectRatio="none">
                    <path d="M0 640 C 200 540, 380 780, 800 580 L 800 900 L 0 900 Z" fill="currentColor" />
                    <path d="M0 250 C 260 90, 520 300, 800 130 L 800 0 L 0 0 Z" fill="currentColor" />
                </svg>

                <div class="relative flex items-center gap-4">
                    <span aria-hidden="true" class="grid size-14 place-items-center rounded-2xl bg-white text-3xl font-extrabold text-violet-700 shadow-xl shadow-violet-950/30">Q</span>
                    <span class="text-3xl font-bold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                </div>

                {{-- Illustration sits to the right of the headline, as in the reference --}}
                <svg aria-hidden="true" class="pointer-events-none absolute top-[12%] right-[5%] hidden w-[25%] xl:block" viewBox="0 0 230 360" fill="none">
                    <rect x="22" y="0" width="184" height="102" rx="22" fill="white" fill-opacity="0.92" />
                    <rect x="44" y="26" width="46" height="46" rx="12" fill="#7C3AED" />
                    <path d="M55 40 L67 50 L79 40" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    <rect x="104" y="30" width="92" height="12" rx="6" fill="#DDD6FE" />
                    <rect x="104" y="54" width="62" height="12" rx="6" fill="#DDD6FE" />
                    <path d="M140 102 C 150 118, 160 124, 165 132" stroke="white" stroke-opacity="0.75" stroke-width="2.5" stroke-dasharray="5 6" fill="none" />
                    <circle cx="178" cy="150" r="36" fill="#22C55E" />
                    <path d="M166 141 q4 14 15 19 l5-5 -6-5 -4 3 q-5-3-7-7 l3-3 -5-6 z" fill="white" />
                    <path d="M172 186 C 168 204, 150 214, 136 222" stroke="white" stroke-opacity="0.75" stroke-width="2.5" stroke-dasharray="5 6" fill="none" />
                    <circle cx="112" cy="240" r="36" fill="#2563EB" />
                    <text x="112" y="245" text-anchor="middle" font-size="16" font-weight="800" fill="white" font-family="sans-serif">SMS</text>
                    <path d="M110 276 C 120 290, 130 300, 140 308" stroke="white" stroke-opacity="0.75" stroke-width="2.5" stroke-dasharray="5 6" fill="none" />
                    <circle cx="178" cy="326" r="34" fill="#DDD6FE" />
                    <circle cx="178" cy="326" r="13" fill="none" stroke="#7C3AED" stroke-width="7" stroke-dasharray="4 4" />
                    <circle cx="178" cy="326" r="8" fill="none" stroke="#7C3AED" stroke-width="3" />
                </svg>

                <div class="relative">
                    <h2 class="text-[52px] leading-[1.1] font-bold tracking-tight">Every quote gets a</h2>
                    <h2 class="relative mt-1 inline-block text-[52px] leading-[1.1] font-bold tracking-tight">
                        <span class="text-violet-200">follow-up.</span>
                        <svg aria-hidden="true" class="absolute -bottom-3 left-0 w-full" viewBox="0 0 300 12" preserveAspectRatio="none" fill="none">
                            <path d="M4 8 C 90 2, 200 2, 296 6" stroke="#C4B5FD" stroke-width="4" stroke-linecap="round" />
                        </svg>
                    </h2>
                    <p class="mt-8 max-w-md text-[19px] leading-relaxed text-violet-100/90">Send estimates, follow up on time, and see the replies that matter most.</p>

                    <ul class="mt-14 grid grid-cols-3 gap-6">
                        <li>
                            <span class="grid size-16 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/25 ring-inset"><x-ui-icon name="clock" class="size-8" /></span>
                            <p class="mt-5 text-[18px] leading-snug font-bold whitespace-nowrap">Automatic follow-ups</p>
                            <p class="mt-2 text-[15px] leading-relaxed text-violet-100/90">that stop when a customer replies.</p>
                        </li>
                        <li>
                            <span class="grid size-16 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/25 ring-inset"><x-ui-icon name="chat" class="size-8" /></span>
                            <p class="mt-5 text-[18px] leading-snug font-bold whitespace-nowrap">WhatsApp &amp; SMS</p>
                            <p class="mt-2 text-[15px] leading-relaxed text-violet-100/90">reach customers on their preferred channel.</p>
                        </li>
                        <li>
                            <span class="grid size-16 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/25 ring-inset"><x-ui-icon name="bolt" class="size-8" /></span>
                            <p class="mt-5 text-[18px] leading-snug font-bold whitespace-nowrap">Powerful Automation</p>
                            <p class="mt-2 text-[15px] leading-relaxed text-violet-100/90">save time and grow your business.</p>
                        </li>
                    </ul>
                </div>

                <p class="relative flex items-center gap-3 text-[17px] text-violet-100">
                    <x-ui-icon name="shield" class="size-7 text-violet-200" />
                    Built for trades and service businesses
                </p>
            </aside>

            <main id="main" class="relative flex items-start justify-center overflow-hidden px-6 pt-16 pb-12 sm:px-12 lg:pt-28">
                <span aria-hidden="true" class="pointer-events-none absolute -top-32 -right-32 size-[26rem] rounded-full bg-violet-200/70"></span>
                <span aria-hidden="true" class="pointer-events-none absolute -right-24 -bottom-40 size-[22rem] rounded-full bg-violet-200/60"></span>
                <div class="relative w-full max-w-[34rem]">
                    <div class="mb-10 flex items-center justify-center gap-2 lg:hidden">
                        <span aria-hidden="true" class="grid size-10 place-items-center rounded-xl bg-violet-600 text-base font-bold text-white">Q</span>
                        <span class="text-lg font-semibold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                    </div>
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>

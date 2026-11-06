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
    <body class="h-full font-sans text-[#14113b] antialiased">
        <div class="grid min-h-full lg:grid-cols-[54fr_46fr]">
            {{-- Brand panel. Sizes use the panel's width (cqw) so it keeps the same proportions on every screen. --}}
            <aside class="@container relative hidden overflow-hidden text-white lg:flex lg:flex-col" style="background: radial-gradient(70% 45% at 0% 0%, #6a3cff 0%, transparent 70%), radial-gradient(60% 40% at 100% 100%, #b089ff 0%, transparent 70%), linear-gradient(180deg, #5631f0 0%, #4a22dc 55%, #5a30ea 100%);">
                <span aria-hidden="true" class="auth-drift pointer-events-none absolute top-[-85cqw] left-[35.7cqw] size-[117.6cqw] rounded-full" style="--t: 22s; background: radial-gradient(closest-side, rgba(140,100,255,0.15), rgba(124,85,255,0.42));"></span>
                <span aria-hidden="true" class="auth-drift pointer-events-none absolute top-[-40cqw] left-[-30cqw] size-[80cqw] rounded-full bg-white/[0.06] blur-2xl"></span>
                <svg aria-hidden="true" class="pointer-events-none absolute inset-x-0 bottom-0 h-[26cqw] w-full" viewBox="0 0 952 250" preserveAspectRatio="none">
                    <path d="M0 70 C 180 20, 420 30, 600 80 S 880 120, 952 40 L 952 250 L 0 250 Z" fill="#8b6bff" fill-opacity="0.45" />
                    <path d="M0 130 C 240 90, 470 120, 680 150 S 900 150, 952 110 L 952 250 L 0 250 Z" fill="#a585ff" fill-opacity="0.35" />
                </svg>

                <div class="relative flex flex-1 flex-col justify-between px-[8.1cqw] pt-[7cqw] pb-[7.5cqw]">
                    <div class="auth-fade-up flex items-center gap-[2.6cqw]">
                        <span aria-hidden="true" class="grid size-[7.7cqw] place-items-center rounded-[1.5cqw] bg-white shadow-[0_1cqw_2.5cqw_rgba(30,10,110,0.35)]">
                            <span class="bg-linear-to-br from-violet-500 to-violet-800 bg-clip-text text-[4.8cqw] leading-none font-extrabold text-transparent">Q</span>
                        </span>
                        <span class="text-[4.6cqw] leading-none font-bold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                    </div>

                    @isset($panel)
                        <div class="auth-fade-up relative my-[6cqw]" style="--d: 120ms;">{{ $panel }}</div>
                    @else
                    <div class="relative my-[6cqw]">
                        {{-- Illustration: an estimate card going out by WhatsApp and SMS, with automation. --}}
                        <svg aria-hidden="true" class="pointer-events-none absolute top-[-12.1cqw] left-[63.3cqw] w-[25.2cqw]" viewBox="680 110 240 390" fill="none">
                            <defs>
                                <filter id="signin-shadow" x="-30%" y="-30%" width="160%" height="160%">
                                    <feDropShadow dx="0" dy="8" stdDeviation="9" flood-color="#1e0a6e" flood-opacity="0.35" />
                                </filter>
                                <linearGradient id="signin-beam" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#a88bff" stop-opacity="0.55" />
                                    <stop offset="1" stop-color="#a88bff" stop-opacity="0" />
                                </linearGradient>
                                <linearGradient id="signin-sms" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#3b82f6" />
                                    <stop offset="1" stop-color="#1d4ed8" />
                                </linearGradient>
                            </defs>
                            <path class="auth-pop" style="--d: 450ms;" d="M762 205 L848 200 L866 300 L812 300 Z" fill="url(#signin-beam)" />
                            <g class="auth-pop" style="--d: 300ms;"><g class="auth-float" style="--t: 6s;"><g transform="rotate(-6 790 170)" filter="url(#signin-shadow)">
                                <rect x="697" y="122" width="186" height="96" rx="15" fill="#f4f1ff" />
                                <rect x="720" y="152" width="36" height="36" rx="9" fill="#7c3aed" />
                                <rect x="728.5" y="162.5" width="19" height="15" rx="2.5" stroke="white" stroke-width="2" />
                                <path d="M729.5 164.5 L738 171 L746.5 164.5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                <rect x="773" y="150" width="86" height="11" rx="5.5" fill="#c9bbff" />
                                <rect x="773" y="172" width="60" height="11" rx="5.5" fill="#c9bbff" />
                            </g></g></g>
                            <g class="auth-pop" style="--d: 480ms;">
                                <path d="M765 224 C 758 262, 788 272, 814 270" stroke="white" stroke-width="2.5" stroke-dasharray="5 6" stroke-linecap="round" class="auth-dash" />
                                <path d="M808 263.5 L818 270 L808 276.5" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            </g>

                            <g class="auth-pop" style="--d: 550ms;"><g class="auth-float" style="--t: 5s; --fd: -1.2s;">
                            <g filter="url(#signin-shadow)">
                                <circle cx="860" cy="268" r="38" fill="#eef7f0" />
                                <circle cx="860" cy="268" r="30" fill="#25d366" />
                            </g>
                            <g transform="translate(842 250) scale(1.5)">
                                <path d="M12 3.2a8.8 8.8 0 0 0-7.6 13.2L3.2 20.8l4.5-1.2A8.8 8.8 0 1 0 12 3.2Z" stroke="white" stroke-width="1.6" stroke-linejoin="round" />
                                <path d="M9.1 7.6c.2-.4.4-.4.7-.4h.5c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.2.3 0 .5.6 1 1.4 1.8 2.4 2.3.2.1.4.1.5 0l.6-.6c.2-.2.4-.2.6-.1l1.7.8c.2.1.3.2.3.4v.5c0 .3-.1.6-.4.8-.6.4-1.3.5-2 .3-2.3-.8-4.1-2.6-4.9-4.9-.2-.7-.1-1.5.3-2.1Z" fill="white" />
                            </g>
                            </g></g>

                            <g class="auth-pop" style="--d: 630ms;">
                                <path d="M862 310 C 868 334, 856 352, 838 357" stroke="white" stroke-width="2.5" stroke-dasharray="5 6" stroke-linecap="round" class="auth-dash" />
                                <path d="M845 351 L835 358 L846 363" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            </g>

                            <g class="auth-pop" style="--d: 700ms;"><g class="auth-float" style="--t: 5.5s; --fd: -2.5s;">
                            <g filter="url(#signin-shadow)">
                                <circle cx="794" cy="358" r="37" fill="#e8e3ff" />
                            </g>
                            <path d="M794 334 a23 23 0 1 1 -14.5 41 L772 380 l3.5 -10 A23 23 0 0 1 794 334 Z" fill="url(#signin-sms)" />
                            <text x="795" y="362.5" text-anchor="middle" font-size="13" font-weight="800" fill="white" font-family="ui-sans-serif, system-ui, sans-serif">SMS</text>
                            </g></g>

                            <g class="auth-pop" style="--d: 780ms;">
                                <path d="M796 397 C 798 424, 810 440, 824 443" stroke="white" stroke-width="2.5" stroke-dasharray="5 6" stroke-linecap="round" class="auth-dash" />
                                <path d="M817 437 L827 444 L817 450" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            </g>

                            <g class="auth-pop" style="--d: 850ms;"><g class="auth-float" style="--t: 6.5s; --fd: -3.4s;">
                            <g filter="url(#signin-shadow)">
                                <circle cx="866" cy="444" r="38" fill="#f3f0ff" />
                            </g>
                            <g transform="translate(846.8 424.8) scale(1.6)">
                                <path class="auth-spin" fill="#6d28d9" fill-rule="evenodd" stroke="#6d28d9" stroke-width="0.6" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </g>
                            </g></g>
                        </svg>

                        <h2 class="auth-fade-up text-[7.2cqw] leading-[1.1] font-bold tracking-tight" style="--d: 100ms;">Every quote gets a</h2>
                        <p class="auth-fade-up relative inline-block" style="--d: 200ms;">
                            <span class="inline-block bg-linear-to-b from-white to-[#c9b8ff] bg-clip-text pb-[0.08em] text-[9.6cqw] leading-[1.05] font-extrabold tracking-tight text-transparent">follow-up.</span>
                            <svg aria-hidden="true" class="auth-draw absolute top-full left-[2.7cqw] -mt-[0.6cqw] h-[2.3cqw] w-[31.7cqw]" style="--d: 650ms;" viewBox="0 0 300 22" preserveAspectRatio="none" fill="none">
                                <path d="M2 19 C 70 9, 170 3, 298 2 C 175 7, 80 14, 6 22 Z" fill="#c9b8ff" />
                            </svg>
                        </p>
                        <p class="auth-fade-up mt-[3.4cqw] text-[2.9cqw] leading-[1.43] text-[#e2dcff]" style="--d: 300ms;">Send estimates, follow up on time,<br>and see the replies that matter most.</p>

                        <ul class="mt-[5cqw] grid w-max grid-cols-[29.3cqw_32.9cqw_28cqw]">
                            <li class="auth-fade-up flex items-start gap-[2.5cqw]" style="--d: 450ms;">
                                <span class="grid size-[8.4cqw] shrink-0 place-items-center rounded-[1.9cqw] bg-linear-to-b from-white/25 to-white/10 ring-1 ring-white/20 ring-inset">
                                    <svg aria-hidden="true" class="size-[4.6cqw]" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4.3 13.5A7.8 7.8 0 0 1 17.6 6.5" />
                                        <path d="M18.4 3.2v3.9h-3.9" />
                                        <path d="M19.7 10.5A7.8 7.8 0 0 1 6.4 17.5" />
                                        <path d="M5.6 20.8v-3.9h3.9" />
                                        <path d="M12.9 7.8 9.8 12.6h2.6l-.9 3.6 3.1-4.8h-2.6z" fill="white" stroke-width="1" />
                                    </svg>
                                </span>
                                <div class="pt-[0.6cqw]">
                                    <p class="text-[2.1cqw] leading-[1.3] font-bold">Automatic<br>follow-ups</p>
                                    <p class="mt-[1cqw] text-[1.6cqw] leading-[1.45] text-[#ddd6ff]">that stop when a<br>customer replies.</p>
                                </div>
                            </li>
                            <li class="auth-fade-up flex items-start gap-[2.5cqw]" style="--d: 570ms;">
                                <span class="grid size-[8.4cqw] shrink-0 place-items-center rounded-[1.9cqw] bg-linear-to-b from-white/25 to-white/10 ring-1 ring-white/20 ring-inset">
                                    <svg aria-hidden="true" class="size-[5.2cqw]" viewBox="0 0 24 24" fill="none">
                                        <path d="M12 2.4a9.6 9.6 0 0 0-8.3 14.4L2.4 21.6l4.9-1.3A9.6 9.6 0 1 0 12 2.4Z" fill="#25d366" stroke="white" stroke-width="1.4" stroke-linejoin="round" />
                                        <path d="M9.1 7.6c.2-.4.4-.4.7-.4h.5c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.2.3 0 .5.6 1 1.4 1.8 2.4 2.3.2.1.4.1.5 0l.6-.6c.2-.2.4-.2.6-.1l1.7.8c.2.1.3.2.3.4v.5c0 .3-.1.6-.4.8-.6.4-1.3.5-2 .3-2.3-.8-4.1-2.6-4.9-4.9-.2-.7-.1-1.5.3-2.1Z" fill="white" />
                                    </svg>
                                </span>
                                <div class="pt-[0.6cqw]">
                                    <p class="text-[2.1cqw] leading-[1.3] font-bold">WhatsApp &amp;<br>SMS</p>
                                    <p class="mt-[1cqw] text-[1.6cqw] leading-[1.45] text-[#ddd6ff]">reach customers<br>on their preferred channel.</p>
                                </div>
                            </li>
                            <li class="auth-fade-up flex items-start gap-[2.5cqw]" style="--d: 690ms;">
                                <span class="grid size-[8.4cqw] shrink-0 place-items-center rounded-[1.9cqw] bg-linear-to-b from-white/25 to-white/10 ring-1 ring-white/20 ring-inset">
                                    <svg aria-hidden="true" class="size-[4.8cqw]" viewBox="0 0 24 24" fill="none">
                                        <path d="M12 3v2.6" stroke="white" stroke-width="1.8" stroke-linecap="round" />
                                        <circle cx="12" cy="2.6" r="1.4" fill="white" />
                                        <rect x="4.2" y="6" width="15.6" height="12.4" rx="4" fill="white" />
                                        <rect x="1.8" y="9.6" width="2.2" height="5" rx="1.1" fill="white" />
                                        <rect x="20" y="9.6" width="2.2" height="5" rx="1.1" fill="white" />
                                        <circle cx="9.1" cy="11.6" r="1.7" fill="#6d28d9" />
                                        <circle cx="14.9" cy="11.6" r="1.7" fill="#6d28d9" />
                                        <path d="M9.6 15h4.8" stroke="#6d28d9" stroke-width="1.6" stroke-linecap="round" />
                                    </svg>
                                </span>
                                <div class="pt-[0.6cqw]">
                                    <p class="text-[2.1cqw] leading-[1.3] font-bold">Powerful<br>Automation</p>
                                    <p class="mt-[1cqw] text-[1.6cqw] leading-[1.45] text-[#ddd6ff]">save time and<br>grow your business.</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                    @endisset

                    <div class="auth-fade-up" style="--d: 850ms;">
                        <span aria-hidden="true" class="block h-px w-[42cqw] bg-white/25"></span>
                        <p class="mt-[3cqw] flex items-center gap-[1.8cqw] text-[2.1cqw] leading-none text-white/95">
                            <svg aria-hidden="true" class="size-[3.3cqw] shrink-0" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2.2 4 5.2v6.1c0 5 3.4 9.1 8 10.5 4.6-1.4 8-5.5 8-10.5V5.2l-8-3Z" fill="#d4c6ff" />
                                <path d="m8.4 12.1 2.5 2.5 4.8-5" stroke="#5b2ee6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Built for trades and service businesses
                        </p>
                    </div>
                </div>
            </aside>

            <main id="main" class="relative flex items-center justify-center overflow-hidden px-6 py-12 sm:px-12">
                <span aria-hidden="true" class="auth-drift pointer-events-none absolute -top-[16rem] -right-[15rem] size-[26rem] rounded-full bg-[#f6f3ff]"></span>
                <span aria-hidden="true" class="auth-drift pointer-events-none absolute -top-[8.75rem] -right-[8.5rem] size-[15rem] rounded-full bg-[#efeaff]" style="--t: 12s;"></span>
                <span aria-hidden="true" class="auth-drift pointer-events-none absolute -right-[9.5rem] -bottom-[11.4rem] size-[16rem] rounded-full bg-[#f6f3ff]"></span>
                <span aria-hidden="true" class="pointer-events-none absolute -right-[5.75rem] -bottom-[7.4rem] size-[10rem] rounded-full bg-[#efeaff]"></span>
                <div class="auth-fade-up relative w-full max-w-[515px]" style="--d: 150ms;">
                    <div class="mb-10 flex items-center justify-center gap-2.5 lg:hidden">
                        <span aria-hidden="true" class="grid size-10 place-items-center rounded-xl bg-violet-600 text-lg font-extrabold text-white">Q</span>
                        <span class="text-xl font-bold tracking-tight">{{ config('app.name', 'QuoteFollow') }}</span>
                    </div>
                    {{ $slot }}
                </div>
            </main>
        </div>
        {{-- Loads Alpine (bundled with Livewire) for the show/hide password and strength meter. --}}
        @livewireScripts
    </body>
</html>

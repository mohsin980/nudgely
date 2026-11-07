@php($input = 'mt-2 block w-full rounded-2xl border-0 bg-white px-4 py-3.5 text-[17px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600')
@php($iconInput = 'block w-full rounded-2xl border-0 bg-white py-3.5 pr-4 pl-14 text-[17px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600')
@php($fieldIcon = 'pointer-events-none absolute top-1/2 left-4 size-6 -translate-y-1/2 text-slate-700')
@php($label = 'block text-lg font-bold text-slate-900')
@php($title = 'text-[34px] leading-tight font-extrabold tracking-tight text-[#14112b] sm:text-[44px]')
@php($lead = 'mt-3 text-lg text-slate-600 sm:text-xl')
@php($primary = 'inline-flex w-full items-center justify-center gap-2.5 rounded-2xl bg-linear-to-r from-violet-700 to-violet-500 px-8 py-4 text-lg font-bold text-white shadow-[0_14px_28px_-10px_rgba(109,40,217,0.7)] transition hover:from-violet-800 hover:to-violet-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2 disabled:opacity-50 sm:w-auto')
@php($secondary = 'inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-white px-8 py-4 text-lg font-bold text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2 disabled:opacity-50 sm:w-auto')
@php($groups = $state->groups())
@php($check = '<svg viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>')
<div class="lg:grid lg:min-h-screen lg:grid-cols-[17rem_minmax(0,1fr)]" data-onboarding data-step="{{ $step->value }}">
    {{-- Progress: a sidebar on desktop, a compact block on phones. Reflects saved state only. --}}
    <aside aria-label="Setup progress" class="flex flex-col gap-6 border-slate-200/70 bg-white/55 px-5 py-6 backdrop-blur lg:sticky lg:top-0 lg:h-screen lg:border-r lg:px-6 lg:py-7">
        <a href="{{ url('/') }}" class="flex items-center gap-3 text-[22px] font-bold tracking-tight text-slate-900">
            <span aria-hidden="true" class="grid size-12 place-items-center rounded-2xl bg-linear-to-br from-violet-700 to-violet-400 text-xl font-extrabold text-white shadow-[0_10px_20px_-8px_rgba(109,40,217,0.7)]">Q</span>
            {{ config('app.name', 'QuoteFollow') }}
        </a>

        <div class="lg:mt-4">
            <p class="text-xl font-bold text-slate-900">Getting started</p>
            <div class="mt-3 h-2 rounded-full bg-violet-100" role="progressbar" aria-label="Setup progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $state->percent() }}">
                <div class="h-2 rounded-full bg-linear-to-r from-violet-700 to-violet-500" style="width: {{ max($state->percent(), 6) }}%"></div>
            </div>
            <p class="mt-2.5 text-base text-slate-600" data-percent>{{ $state->percent() }}% complete</p>
        </div>

        <ol class="flex flex-wrap gap-2 lg:flex-col lg:gap-1.5" data-progress>
            @foreach ($groups as $index => $group)
                <li @class(['flex items-center gap-3.5 rounded-2xl px-2 py-2 text-[17px] lg:px-2.5', 'bg-violet-100/70 font-bold text-violet-700' => $group['current'], 'text-slate-700' => ! $group['current']]) data-group="{{ $group['label'] }}" data-status="{{ $group['status'] }}" @if ($group['current']) aria-current="step" @endif>
                    <span aria-hidden="true" @class(['grid size-8 shrink-0 place-items-center rounded-full text-[15px] font-semibold', 'bg-emerald-500 text-white' => $group['status'] === 'done', 'bg-slate-200 text-slate-600' => $group['status'] === 'skipped', 'bg-violet-600 text-white' => $group['status'] === 'pending' && $group['current'], 'bg-slate-100 text-slate-700 ring-1 ring-slate-200' => $group['status'] === 'pending' && ! $group['current']])>
                        @if ($group['status'] === 'done'){!! $check !!}@else{{ $index + 1 }}@endif
                    </span>
                    <span>{{ $group['label'] }}</span>
                    <span class="sr-only">{{ $group['status'] === 'done' ? '(done)' : ($group['status'] === 'skipped' ? '(skipped)' : '(to do)') }}</span>
                </li>
            @endforeach
        </ol>

        @if ($trial)
            <div class="rounded-2xl bg-violet-100/50 p-4 ring-1 ring-violet-100" data-trial>
                <p class="flex items-start gap-3 text-[15px] text-slate-700">
                    <x-ui-icon name="clock" class="mt-0.5 size-7 shrink-0 text-violet-600" />
                    <span>Your trial ends in {{ $trial['days_left'] }} {{ \Illuminate\Support\Str::plural('day', $trial['days_left']) }}.</span>
                </p>
                @can('manage-billing')
                    <a href="{{ route('settings.billing.plans') }}" class="mt-3 ml-10 inline-flex items-center gap-1.5 rounded-full bg-white px-4 py-1.5 text-[15px] font-semibold text-violet-700 ring-1 ring-violet-300 transition hover:bg-violet-50">View plans <x-ui-icon name="arrow-right" class="size-4 stroke-2" /></a>
                @endcan
            </div>
        @endif

        @if (config('app.support_email'))
            <div class="mt-auto hidden rounded-2xl bg-white/80 p-4 ring-1 ring-slate-200/70 lg:block">
                <div class="flex items-start gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-violet-100 text-violet-600"><svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 14v-2a8 8 0 0 1 16 0v2M4 14a2 2 0 0 0 2 2h1v-5H6a2 2 0 0 0-2 2m16 1a2 2 0 0 1-2 2h-1v-5h1a2 2 0 0 1 2 2m-3 3c0 1.5-2 3-5 3"/></svg></span>
                    <div>
                        <p class="text-[17px] font-bold text-slate-900">Need help?</p>
                        <p class="text-sm text-slate-600">Our team is here for you.</p>
                        <a href="mailto:{{ config('app.support_email') }}" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-violet-700 underline underline-offset-2 hover:text-violet-800">Contact support <x-ui-icon name="arrow-right" class="size-4 stroke-2" /></a>
                    </div>
                </div>
            </div>
        @endif
    </aside>

    <div class="min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-7">
        @include('livewire.onboarding.partials.top-bar')

    <section class="relative overflow-hidden rounded-[28px] bg-white/95 p-6 shadow-[0_24px_60px_-28px_rgba(76,29,149,0.35)] ring-1 ring-white sm:p-10" aria-live="polite">
        @if ($error)
            <p role="alert" class="mb-4 rounded-2xl bg-red-50 p-3 text-sm text-red-800" data-error>{{ $error }}</p>
        @endif

        @php($art = match (true) { ! $welcomed => null, $step === \App\Enums\Onboarding\OnboardingStep::BusinessProfile => 'store', $step === \App\Enums\Onboarding\OnboardingStep::FirstAutomation && $review === null => 'automation', default => null })
        @if ($art)
            <x-illustration :name="$art" class="pointer-events-none absolute top-6 right-6 hidden h-44 w-auto xl:block" />
        @endif

        @if ($welcomed && $stepNumber)
            <div class="relative mb-6 flex items-center justify-between gap-3" data-step-counter>
                <span class="inline-flex rounded-full bg-violet-100 px-4 py-1.5 text-[15px] font-semibold text-violet-700">Step {{ $stepNumber }} of {{ $stepTotal }}</span>
                @if ($canGoBack)
                    <button type="button" wire:click="goBack" wire:loading.attr="disabled" @class(['inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50', 'xl:hidden' => $art !== null]) data-back><x-ui-icon name="arrow-left" class="size-4 stroke-2" /> Back</button>
                @endif
            </div>
        @endif

        @if (! $welcomed)
            {{-- Welcome --}}
            <span class="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-3 py-1 text-xs font-medium text-violet-700" data-welcome-badge>
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="size-4"><path d="M10 2l1.6 4.4L16 8l-4.4 1.6L10 14l-1.6-4.4L4 8l4.4-1.6L10 2z"/></svg>
                Getting started
            </span>
            <h1 class="mt-4 {{ $title }}">Welcome to QuoteFollow</h1>
            <p class="mt-3 max-w-xl text-base text-slate-600">Let's get your business ready in a few minutes. You can skip anything optional and come back to it later.</p>

            <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_16rem]">
                <section aria-labelledby="welcome-steps" class="rounded-xl border border-slate-200 bg-white p-2">
                    <h2 id="welcome-steps" class="sr-only">Setup steps</h2>
                    @php($currentGroup = collect($groups)->search(fn ($group) => $group['current']))
                    <ol class="divide-y divide-slate-100" data-welcome-steps>
                        @foreach ($groups as $i => $group)
                            {{-- A step opens only when reached: finished steps can be reviewed, later ones open in order. --}}
                            @php($reachable = $currentGroup !== false && $i <= $currentGroup)
                            <li data-welcome-step="{{ $group['status'] }}">
                                <button type="button" wire:click="viewStep('{{ $group['step'] }}')" @disabled(! $reachable) data-open-step="{{ $group['step'] }}" class="group flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left transition-colors hover:bg-violet-50/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-600 disabled:cursor-not-allowed disabled:hover:bg-transparent">
                                    <span aria-hidden="true" @class(['grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold', 'bg-emerald-500 text-white' => $group['status'] === 'done', 'bg-violet-600 text-white' => $group['current'] && $group['status'] !== 'done', 'bg-slate-100 text-slate-600' => ! $group['current'] && $group['status'] !== 'done'])>
                                        @if ($group['status'] === 'done')
                                            <svg viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                                        @else
                                            {{ $i + 1 }}
                                        @endif
                                    </span>
                                    <span @class(['flex-1 text-sm font-medium', 'text-slate-900' => $reachable, 'text-slate-400' => ! $reachable])>{{ $group['label'] }}</span>
                                    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-medium', 'bg-emerald-50 text-emerald-700' => $group['status'] === 'done', 'bg-slate-100 text-slate-600' => $group['status'] !== 'done'])>{{ $group['status'] === 'done' ? 'Done' : ($group['status'] === 'skipped' ? 'Skipped' : 'To do') }}</span>
                                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="size-4 shrink-0 text-slate-400 transition-transform group-hover:translate-x-0.5"><path fill-rule="evenodd" d="M7.2 4.2a1 1 0 011.4 0l4.6 4.6a1 1 0 010 1.4l-4.6 4.6a1 1 0 01-1.4-1.4L11.3 10 7.2 5.6a1 1 0 010-1.4z" clip-rule="evenodd"/></svg>
                                </button>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <aside class="rounded-xl bg-violet-50/60 p-5 text-sm text-slate-700" aria-labelledby="welcome-needs">
                    <h2 id="welcome-needs" class="font-semibold text-slate-900">What you'll need</h2>
                    <ul class="mt-3 space-y-2">
                        <li class="flex gap-2"><span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-violet-600"></span>Your business name and type</li>
                        <li class="flex gap-2"><span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-violet-600"></span>The email address you send quotes from</li>
                        <li class="flex gap-2"><span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-violet-600"></span>One customer and one estimate to start</li>
                    </ul>
                </aside>
            </div>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <button type="button" wire:click="start" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2 disabled:opacity-50">
                    Get started
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.64l-3.22-3.22a.75.75 0 111.06-1.06l4.5 4.5a.75.75 0 010 1.06l-4.5 4.5a.75.75 0 11-1.06-1.06l3.22-3.22H3.75A.75.75 0 013 10z" clip-rule="evenodd"/></svg>
                </button>
                <button type="button" wire:click="skipAll" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
            </div>
            <p class="mt-4 flex items-start gap-2 text-xs text-slate-500">
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 size-4 shrink-0 text-violet-500"><path d="M10 1.5a6 6 0 00-3.5 10.9V14a1 1 0 001 1h5a1 1 0 001-1v-1.6A6 6 0 0010 1.5zM8 17h4v.5a2 2 0 01-4 0V17z"/></svg>
                Skipping leaves everything as it is. A short checklist on your dashboard helps you finish later.
            </p>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::BusinessProfile)
            <h1 class="relative {{ $title }}">About your business</h1>
            <p class="relative {{ $lead }}">Just the basics. You can change these any time in Settings.</p>
            <form wire:submit="saveBusiness" class="relative mt-8 space-y-6" novalidate>
                <div>
                    <label for="businessName" class="{{ $label }}">Business name</label>
                    <div class="relative mt-2.5"><x-ui-icon name="briefcase" class="{{ $fieldIcon }}" /><input id="businessName" type="text" wire:model="businessName" class="{{ $iconInput }}" required autocomplete="organization"></div>
                    @error('businessName') <p class="mt-1.5 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="businessType" class="{{ $label }}">Business type</label>
                    <div class="relative mt-2.5">
                        <svg class="{{ $fieldIcon }}" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linejoin="round" d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
                        <select id="businessType" wire:model="businessType" class="{{ $iconInput }} appearance-none pr-12" required>
                            <option value="">Choose one…</option>
                            @foreach ($businessTypes as $value => $typeLabel)<option value="{{ $value }}">{{ $typeLabel }}</option>@endforeach
                        </select>
                        <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-5 size-5 -translate-y-1/2 stroke-2 text-slate-800" />
                    </div>
                    @error('businessType') <p class="mt-1.5 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label for="website" class="{{ $label }}">Website <span class="font-normal text-slate-500">(optional)</span></label>
                        <div class="relative mt-2.5"><x-ui-icon name="globe" class="{{ $fieldIcon }}" /><input id="website" type="text" wire:model="website" placeholder="dallashvac.com" class="{{ $iconInput }}" autocomplete="url"></div>
                        @error('website') <p class="mt-1.5 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phone" class="{{ $label }}">Phone <span class="font-normal text-slate-500">(optional)</span></label>
                        <div class="relative mt-2.5"><x-ui-icon name="phone" class="{{ $fieldIcon }}" /><input id="phone" type="tel" wire:model="phone" placeholder="(214) 555-1234" class="{{ $iconInput }}" autocomplete="tel"></div>
                        @error('phone') <p class="mt-1.5 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveBusiness" class="{{ $primary }}"><span wire:loading.remove wire:target="saveBusiness" class="inline-flex items-center gap-2.5">Continue <x-ui-icon name="arrow-right" class="size-5 stroke-2" /></span><span wire:loading wire:target="saveBusiness">Saving…</span></button>
                    <button type="button" wire:click="skipAll" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
                </div>
            </form>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::BusinessPreferences)
            <h1 class="relative {{ $title }}">Where are you located?</h1>
            <p class="relative {{ $lead }}">We use this to send follow-ups at sensible local times. No full address needed.</p>
            <form wire:submit="saveLocation" class="mt-5 space-y-4" novalidate>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="country" class="{{ $label }}">Country</label>
                        <select id="country" wire:model.live="country" class="{{ $input }}">@foreach ($countries as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                        @error('country') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="state" class="{{ $label }}">{{ $regionLabel }} <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="state" type="text" wire:model.live.debounce.500ms="stateCode" maxlength="50" placeholder="{{ $country === 'US' ? 'TX' : '' }}" class="{{ $input }}" autocomplete="address-level1">
                        @error('stateCode') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="city" class="{{ $label }}">City</label>
                        <input id="city" type="text" wire:model="city" placeholder="{{ $country === 'US' ? 'Dallas' : '' }}" class="{{ $input }}" autocomplete="address-level2">
                        @error('city') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="timezone" class="{{ $label }}">Timezone</label>
                    <select id="timezone" wire:model.live="timezone" class="{{ $input }}">
                        @foreach ($timezones as $zone => $label)<option value="{{ $zone }}">{{ $label }} ({{ $zone }})</option>@endforeach
                        @unless (array_key_exists($timezone, $timezones))<option value="{{ $timezone }}">{{ $timezone }}</option>@endunless
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Suggested from your {{ $country === 'US' ? 'state' : 'country' }}. Change it if it's wrong.</p>
                    @error('timezone') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveLocation" class="{{ $primary }}"><span wire:loading.remove wire:target="saveLocation">Continue</span><span wire:loading wire:target="saveLocation">Saving…</span></button>
                    <button type="button" wire:click="skip('business_preferences')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                </div>
            </form>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FollowUpChannels)
            <h1 class="relative {{ $title }}">How do you want to follow up?</h1>
            <p class="relative {{ $lead }}">Choose how QuoteFlow contacts your customers. You can change this any time, and pick a channel per customer.</p>
            <form wire:submit="saveChannels" class="relative mt-8 space-y-6" novalidate>
                <div class="grid gap-4 md:grid-cols-3" role="radiogroup" aria-label="Follow-up channels" data-channel-choices>
                    @foreach ([
                        'email' => ['envelope', 'Email', 'Follow up from your business email address.'],
                        'whatsapp' => ['chat', 'WhatsApp', 'Follow up from your WhatsApp Business number.'],
                        'both' => ['arrows-right-left', 'Both', 'Some customers by email, some on WhatsApp.'],
                    ] as $value => [$icon, $optionLabel, $help])
                        <label @class(['flex cursor-pointer flex-col gap-3 rounded-2xl p-5 ring-1 transition', 'bg-violet-50/70 ring-2 ring-violet-400 shadow-[0_12px_30px_-18px_rgba(109,40,217,0.5)]' => $channelChoice === $value, 'bg-white ring-slate-200 hover:ring-violet-200' => $channelChoice !== $value])>
                            <span class="flex items-center justify-between">
                                <span @class(['grid size-14 place-items-center rounded-2xl', 'bg-linear-to-b from-violet-500 to-violet-700 text-white' => $channelChoice === $value, 'bg-violet-50 text-violet-600' => $channelChoice !== $value])><x-ui-icon :name="$icon" class="size-7" /></span>
                                <input type="radio" wire:model.live="channelChoice" value="{{ $value }}" class="size-5 border-slate-300 text-violet-600 focus:ring-violet-500" data-channel="{{ $value }}">
                            </span>
                            <span class="text-xl font-bold text-slate-900">{{ $optionLabel }}</span>
                            <span class="text-[15px] text-slate-600">{{ $help }}</span>
                        </label>
                    @endforeach
                </div>
                @error('channels') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                @if ($channelChoice === 'both')
                    <div class="sm:max-w-sm">
                        <label for="primaryChannel" class="{{ $label }}">Main channel</label>
                        <select id="primaryChannel" wire:model="primaryChannel" class="{{ $input }}">
                            <option value="email">Email</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                        <p class="mt-1.5 text-sm text-slate-500">For customers who haven't picked one. Email is used when WhatsApp isn't possible for a customer.</p>
                    </div>
                @endif
                @if (in_array($channelChoice, ['whatsapp', 'both'], true))
                    <p class="flex items-start gap-2.5 rounded-2xl bg-emerald-50 p-4 text-[15px] text-emerald-900 ring-1 ring-emerald-100"><x-ui-icon name="info" class="mt-0.5 size-5 shrink-0 stroke-2" />WhatsApp uses your own WhatsApp Business number on Meta, which bills you directly for messages. Customers must agree to WhatsApp messages first.</p>
                @endif
                <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveChannels" class="{{ $primary }}"><span wire:loading.remove wire:target="saveChannels" class="inline-flex items-center gap-2.5">Continue <x-ui-icon name="arrow-right" class="size-5 stroke-2" /></span><span wire:loading wire:target="saveChannels">Saving…</span></button>
                    <button type="button" wire:click="skip('follow_up_channels')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip (use email)</button>
                </div>
            </form>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::WhatsAppConnection)
            @php($waActive = $whatsappConnection?->isActive() ?? false)
            <h1 class="relative {{ $title }}">Connect your WhatsApp number</h1>
            <p class="relative {{ $lead }}">Use your own WhatsApp Business number. It takes about 20 minutes once, and our guide walks you through it.</p>
            <div class="relative mt-6 flex items-start gap-3 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <span aria-hidden="true" @class(['mt-0.5 grid size-6 shrink-0 place-items-center rounded-full', 'bg-emerald-500 text-white' => $waActive, 'bg-amber-100 text-amber-700' => ! $waActive])>@if ($waActive){!! $check !!}@else<span class="size-2 rounded-full bg-current"></span>@endif</span>
                <p class="text-[15px] text-slate-700" data-whatsapp-status>
                    @if ($waActive)
                        Connected: <span class="font-semibold text-slate-900">{{ $whatsappConnection->display_phone_number ?? $whatsappConnection->phone_number_id }}</span>
                    @elseif ($whatsappConnection)
                        Not working yet. {{ $whatsappConnection->last_error }}
                    @else
                        Status: <span class="font-semibold text-slate-900">Not connected</span>
                    @endif
                </p>
            </div>
            <div class="relative mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <a href="{{ route('settings.whatsapp') }}" class="{{ $primary }}">Connect WhatsApp <x-ui-icon name="arrow-right" class="size-5 stroke-2" /></a>
                <button type="button" wire:click="recheckWhatsApp" wire:loading.attr="disabled" class="{{ $secondary }}">I've connected it, check again</button>
                <button type="button" wire:click="skip('whatsapp_connection')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
            </div>
            @unless ($waActive)
                <p class="relative mt-4 flex items-start gap-2 text-sm text-slate-500">Until WhatsApp is connected, follow-ups go by email when the customer has an email address.</p>
            @endunless

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::EmailConnection)
            <h1 class="relative {{ $title }}">Connect your business email</h1>
            <p class="relative {{ $lead }}">Connect the email address your business uses to communicate with customers.</p>
            <p class="mt-3 text-sm text-slate-600">QuoteFollow can send follow-ups and estimates to your customers from that address, and show their replies in your conversations. You approve anything automatic.</p>
            @php($account = $emailAccount)
            @php($active = $account !== null && $account->isVerified() && $account->connection_status === \App\Models\EmailConnection::STATUS_ACTIVE)
            @php($providerLabel = match ($account?->provider) { \App\Enums\EmailProvider::Gmail => 'Gmail', \App\Enums\EmailProvider::Microsoft => 'Outlook or Microsoft 365', \App\Enums\EmailProvider::Smtp => 'custom SMTP', default => null })

            <div class="mt-6 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <span aria-hidden="true" @class(['mt-0.5 grid size-6 shrink-0 place-items-center rounded-full', 'bg-emerald-500 text-white' => $active, 'bg-amber-100 text-amber-700' => ! $active])>
                    @if ($active)
                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-3.5"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                    @else
                        <span class="size-2 rounded-full bg-current"></span>
                    @endif
                </span>
                <div class="text-sm">
                    @if ($account === null)
                        <p class="text-slate-700" data-email-status>Status: <span class="font-medium text-slate-900">Not connected</span></p>
                    @elseif ($account->connection_status === \App\Models\EmailConnection::STATUS_REAUTH_REQUIRED)
                        <p class="text-amber-800" data-email-status>Needs reconnecting: <span class="font-medium">{{ $account->sender_email }}</span>. Follow-ups and estimates can't be sent until you reconnect it.</p>
                    @elseif (! $active)
                        <p class="text-slate-700" data-email-status>Not verified yet: <span class="font-medium">{{ $account->sender_email }}</span>. Finish connecting it to continue.</p>
                    @else
                        <p class="text-slate-700" data-email-status>Connected: <span class="font-medium text-slate-900">{{ $account->sender_email }}</span> via {{ $providerLabel }}</p>
                    @endif
                </div>
            </div>

            @if ($testEmailStatus)
                <p role="status" class="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800" data-test-email-sent>{{ $testEmailStatus }}</p>
            @endif

            @if ($active)
                <div class="mt-4">
                    <button type="button" wire:click="sendTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail" class="{{ $secondary }} sm:w-auto" data-send-test-email><span wire:loading.remove wire:target="sendTestEmail">Send a test email to me</span><span wire:loading wire:target="sendTestEmail">Sending…</span></button>
                </div>
            @endif

            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('settings.email', ['from' => 'onboarding']) }}" class="{{ $primary }}">Connect Email</a>
                <button type="button" wire:click="recheckEmail" wire:loading.attr="disabled" class="{{ $secondary }}">I've connected it — check again</button>
                <button type="button" wire:click="skip('email_connection')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
            </div>
            @unless ($active)
                <p class="mt-4 flex items-start gap-2 text-xs text-slate-500" data-email-skip-warning>
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 size-4 shrink-0 text-violet-500"><path d="M10 1.5a6 6 0 00-3.5 10.9V14a1 1 0 001 1h5a1 1 0 001-1v-1.6A6 6 0 0010 1.5zM8 17h4v.5a2 2 0 01-4 0V17z"/></svg>
                    Without a working email account, follow-ups and estimates can't be sent. You can connect one later in Settings.
                </p>
            @endunless

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FirstCustomer)
            <h1 class="relative {{ $title }}">Add a customer to try QuoteFollow</h1>
            <p class="relative {{ $lead }}">Someone you've quoted or might quote. Only a name is required.</p>
            <form wire:submit="addCustomer" class="mt-5 space-y-4" novalidate>
                <div>
                    <label for="customerName" class="{{ $label }}">Name</label>
                    <input id="customerName" type="text" wire:model="customerName" class="{{ $input }}" placeholder="Jane Smith" autocomplete="off">
                    @error('customerName') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="customerEmail" class="{{ $label }}">Email</label>
                        <input id="customerEmail" type="email" wire:model="customerEmail" class="{{ $input }}" autocomplete="off">
                        @error('customerEmail') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="customerPhone" class="{{ $label }}">Phone</label>
                        <input id="customerPhone" type="tel" wire:model="customerPhone" class="{{ $input }}" autocomplete="off">
                        @error('customerPhone') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="customerCompany" class="{{ $label }}">Company <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="customerCompany" type="text" wire:model="customerCompany" class="{{ $input }}" autocomplete="off">
                    @error('customerCompany') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="addCustomer" class="{{ $primary }}"><span wire:loading.remove wire:target="addCustomer">Add Customer</span><span wire:loading wire:target="addCustomer">Adding…</span></button>
                    <button type="button" wire:click="skip('first_customer')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                </div>
            </form>
            <div class="mt-6 rounded-lg bg-slate-50 p-4 text-sm" data-sample-offer>
                <p class="font-medium text-slate-900">Want to explore QuoteFollow first?</p>
                <p class="mt-1 text-slate-500">Create a clearly marked sample customer. It never receives real emails and you can delete it any time.</p>
                <button type="button" wire:click="createSampleCustomer" wire:loading.attr="disabled" class="{{ $secondary }} mt-3">Create Sample Customer</button>
            </div>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FirstEstimate)
            <h1 class="relative {{ $title }}">Create your first estimate</h1>
            <p class="relative {{ $lead }}">A quick draft. You'll review and send it from the estimate page, nothing is sent now.</p>
            @if ($customers->isEmpty())
                <p class="mt-4 text-sm text-slate-700">Add a customer first to create an estimate.</p>
                <div class="mt-4"><button type="button" wire:click="skip('first_estimate')" class="{{ $secondary }}">Skip</button></div>
            @else
                <form wire:submit="createEstimate" class="mt-5 space-y-4" novalidate>
                    <div>
                        <label for="estimateCustomerId" class="{{ $label }}">Customer</label>
                        <select id="estimateCustomerId" wire:model="estimateCustomerId" class="{{ $input }}">
                            <option value="">Choose a customer…</option>
                            @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->is_demo ? ' (sample)' : '' }}</option>@endforeach
                        </select>
                        @error('estimateCustomerId') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-[1fr_10rem]">
                        <div>
                            <label for="estimateTitle" class="{{ $label }}">What is the estimate for?</label>
                            <input id="estimateTitle" type="text" wire:model="estimateTitle" placeholder="AC installation" class="{{ $input }}" maxlength="200">
                            @error('estimateTitle') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="estimatePrice" class="{{ $label }}">Price ({{ $estimateDefaults['currency'] }})</label>
                            <input id="estimatePrice" type="text" inputmode="decimal" wire:model="estimatePrice" placeholder="2500" class="{{ $input }}">
                            @error('estimatePrice') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-xs text-slate-500" data-estimate-defaults>From {{ $estimateDefaults['business'] }} · {{ $estimateDefaults['currency'] }} · valid until {{ $organization->formatCalendarDate(\Carbon\CarbonImmutable::parse($estimateDefaults['valid_until'])) }}@if ($estimateDefaults['notes'] !== '') · default notes included @endif</p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="submit" wire:loading.attr="disabled" wire:target="createEstimate" class="{{ $primary }}"><span wire:loading.remove wire:target="createEstimate">Create Estimate</span><span wire:loading wire:target="createEstimate">Creating…</span></button>
                        <button type="button" wire:click="skip('first_estimate')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                    </div>
                </form>
            @endif

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FirstAutomation)
            @if ($review === null)
                <h1 class="relative {{ $title }}">Set up your first automation</h1>
                <p class="relative {{ $lead }} font-medium">Suggested for {{ $businessTypes[$organization->business_type] ?? 'your business' }}. Nothing runs until you confirm it.</p>
                @php($templateIcons = ['follow_up_after_estimate' => 'envelope', 'ready_to_book' => 'calendar', 'interested' => 'arrow-path', 'estimate_accepted' => 'check-circle', 'new_lead_follow_up' => 'user-plus', 'new_lead_sequence' => 'envelope', 'wants_callback' => 'phone', 'price_objection' => 'dollar'])
                <ul role="list" class="relative mt-10 space-y-4" data-templates x-data="{ picked: '{{ array_key_first($templates) }}' }">
                    @foreach ($templates as $key => $template)
                        <li data-template="{{ $key }}" @click="picked = '{{ $key }}'"
                            :class="picked === '{{ $key }}' ? 'bg-violet-50/70 ring-violet-300 shadow-[0_12px_30px_-18px_rgba(109,40,217,0.5)]' : 'bg-white ring-slate-200 hover:ring-violet-200'"
                            class="flex cursor-pointer items-start gap-5 rounded-2xl p-5 ring-1 transition sm:p-6">
                            <span @class(['grid size-[72px] shrink-0 place-items-center rounded-2xl shadow-[inset_0_1px_0_rgba(255,255,255,0.9),0_8px_20px_-12px_rgba(76,29,149,0.5)]', 'bg-linear-to-b from-violet-500 to-violet-700 text-white' => $loop->first, 'bg-linear-to-b from-violet-50 to-violet-100 text-violet-600' => ! $loop->first])>
                                <x-ui-icon :name="$templateIcons[$key] ?? 'bolt'" class="size-9 stroke-[1.8]" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-3 text-xl font-bold text-slate-900">{{ $template['name'] }} @if ($loop->first)<span class="rounded-full bg-violet-100 px-3 py-0.5 text-[15px] font-semibold text-violet-700">Recommended</span>@endif</p>
                                <p class="mt-1.5 text-[17px] text-slate-600">{{ $template['description'] }}</p>
                                <button type="button" wire:click="chooseTemplate('{{ $key }}')" wire:loading.attr="disabled" class="mt-4 inline-flex items-center gap-2.5 rounded-full bg-white px-5 py-2 text-[15px] font-semibold text-violet-800 ring-1 ring-violet-300 transition hover:bg-violet-50 disabled:opacity-50">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 3.2A1 1 0 0 0 4.8 4v12a1 1 0 0 0 1.5.9l10-6a1 1 0 0 0 0-1.8l-10-6Z"/></svg>
                                    Use This Automation
                                </button>
                            </div>
                            <span aria-hidden="true" :class="picked === '{{ $key }}' ? 'border-violet-600' : 'border-slate-300'" class="mt-2 grid size-7 shrink-0 place-items-center rounded-full border-2 bg-white transition">
                                <span x-show="picked === '{{ $key }}'" class="size-3.5 rounded-full bg-violet-600"></span>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-8 border-t border-slate-200 pt-6"><button type="button" wire:click="skip('first_automation')" wire:loading.attr="disabled" class="rounded-full bg-white px-8 py-3 text-lg font-bold text-slate-900 ring-1 ring-slate-400 transition hover:bg-slate-50 disabled:opacity-50">Skip</button></div>
            @else
                <h1 class="relative {{ $title }}">{{ $review['name'] }}</h1>
                <p class="relative {{ $lead }}">Review exactly what this will do before turning it on.</p>
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-[8rem_1fr]" data-review>
                    <dt class="text-slate-500">When</dt><dd class="text-slate-900" data-review-trigger>{{ $review['trigger'] }}</dd>
                    @if ($review['delay'])<dt class="text-slate-500">Wait</dt><dd class="text-slate-900" data-review-delay>{{ $review['delay'] }}</dd>@endif
                    @if ($review['conditions'] !== [])<dt class="text-slate-500">If</dt><dd class="text-slate-900" data-review-conditions>{{ implode('; ', $review['conditions']) }}</dd>@endif
                    <dt class="text-slate-500">Then</dt><dd class="text-slate-900" data-review-actions>{{ implode('; ', $review['actions']) }}</dd>
                    @if ($review['sends_email'])
                        <dt class="text-slate-500">From</dt><dd class="text-slate-900" data-review-sender>{{ $review['sender'] ?? 'No email connected yet' }}</dd>
                        <dt class="text-slate-500">To</dt><dd class="text-slate-900">{{ $review['recipient'] }}</dd>
                    @endif
                </dl>
                @if ($review['sends_email'])
                    <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900" data-review-warning>
                        This automation emails your customers.
                        @if ($review['unattended']) Emails go out automatically.@else Each email waits for your approval until you allow automatic sending in Settings → Automation.@endif
                        Sample customers are never emailed.
                    </p>
                    @if ($review['sender'] === null)
                        <p class="mt-3 text-sm text-red-700" role="alert">Connect an email address first, or skip this step for now.</p>
                    @endif
                @endif
                <form wire:submit="activateAutomation" class="mt-5 space-y-4" novalidate>
                    <label class="flex items-start gap-2 text-sm text-slate-800">
                        <input type="checkbox" wire:model="confirmed" class="mt-0.5 rounded border-slate-300 text-violet-600"> <span>I've reviewed this and want to turn it on.</span>
                    </label>
                    @error('confirmed') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <button type="submit" wire:loading.attr="disabled" wire:target="activateAutomation" @disabled($review['sends_email'] && $review['sender'] === null) class="{{ $primary }}"><span wire:loading.remove wire:target="activateAutomation">Activate Automation</span><span wire:loading wire:target="activateAutomation">Activating…</span></button>
                        <button type="button" wire:click="customizeAutomation" wire:loading.attr="disabled" class="{{ $secondary }}">Customize</button>
                        <button type="button" wire:click="backToTemplates" class="{{ $secondary }}">Back</button>
                        <button type="button" wire:click="skip('first_automation')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                    </div>
                </form>
            @endif

        @else
            <h1 class="relative {{ $title }}">You're ready to use QuoteFollow.</h1>
            <p class="mt-2 text-sm text-slate-500">Here's where things stand. Anything you skipped is on your dashboard checklist.</p>
            <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4" data-summary>
                @foreach (['customers' => 'Customers', 'estimates' => 'Estimates', 'automations' => 'Automations', 'follow_ups' => 'Follow-ups'] as $key => $label)
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="text-xl font-semibold text-slate-900" data-count="{{ $key }}">{{ $summary[$key] }}</dd></div>
                @endforeach
            </dl>
            @if ($sample)
                <p class="mt-4 text-sm text-slate-500" data-sample-note>You have a sample customer. <button type="button" wire:click="removeSampleData" class="font-medium text-violet-700 underline">Delete sample data</button></p>
            @endif
            <p class="mt-4 text-sm text-slate-700">Working with a team? <a href="{{ route('settings.team') }}" class="font-medium text-violet-700 underline">Invite your team</a> any time.</p>
            <div class="mt-6"><button type="button" wire:click="finish" wire:loading.attr="disabled" class="{{ $primary }}">Go to Dashboard</button></div>
        @endif

        @if ($sample && $step !== \App\Enums\Onboarding\OnboardingStep::Completed && $welcomed)
            <p class="mt-6 border-t border-slate-100 pt-3 text-xs text-slate-500">A sample customer exists (never emailed). <button type="button" wire:click="removeSampleData" class="font-medium text-violet-700 underline">Delete sample data</button></p>
        @endif
    </section>
    </div>
</div>

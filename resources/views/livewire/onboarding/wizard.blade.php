@php($input = 'mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600')
@php($primary = 'inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 sm:w-auto')
@php($secondary = 'inline-flex w-full items-center justify-center rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 disabled:opacity-50 sm:w-auto')
@php($groups = $state->groups())
<div class="space-y-6 lg:grid lg:grid-cols-[14rem_1fr] lg:gap-10 lg:space-y-0" data-onboarding data-step="{{ $step->value }}">
    {{-- Progress: top indicator on phones, left navigation on desktop. Reflects saved state only. --}}
    <aside aria-label="Setup progress" class="space-y-3 lg:pt-1">
        <div>
            <p class="text-sm font-semibold text-gray-900">Getting started</p>
            <div class="mt-2 h-2 rounded-full bg-gray-200" role="progressbar" aria-label="Setup progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $state->percent() }}">
                <div class="h-2 rounded-full bg-indigo-600" style="width: {{ $state->percent() }}%"></div>
            </div>
            <p class="mt-1 text-xs text-gray-600" data-percent>{{ $state->percent() }}% complete</p>
        </div>
        <ol class="flex flex-wrap gap-x-4 gap-y-1 text-sm lg:flex-col lg:gap-y-2" data-progress>
            @foreach ($groups as $group)
                <li @class(['flex items-center gap-2', 'font-semibold text-gray-900' => $group['current'], 'text-gray-600' => ! $group['current']]) data-group="{{ $group['label'] }}" data-status="{{ $group['status'] }}" @if ($group['current']) aria-current="step" @endif>
                    <span aria-hidden="true" @class(['inline-flex h-5 w-5 items-center justify-center rounded-full text-xs', 'bg-green-100 text-green-800' => $group['status'] === 'done', 'bg-gray-100 text-gray-500' => $group['status'] === 'skipped', 'ring-1 ring-gray-300 text-gray-400' => $group['status'] === 'pending'])>{{ $group['status'] === 'done' ? '✓' : ($group['status'] === 'skipped' ? '–' : '○') }}</span>
                    <span>{{ $group['label'] }}</span>
                    <span class="sr-only">{{ $group['status'] === 'done' ? '(done)' : ($group['status'] === 'skipped' ? '(skipped)' : '(to do)') }}</span>
                </li>
            @endforeach
        </ol>
        @if ($trial)
            <p class="rounded-md bg-indigo-50 p-2 text-xs text-indigo-900" data-trial>Your trial ends in {{ $trial['days_left'] }} {{ \Illuminate\Support\Str::plural('day', $trial['days_left']) }}.</p>
        @endif
    </aside>

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:p-6" aria-live="polite">
        @if ($error)
            <p role="alert" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-800" data-error>{{ $error }}</p>
        @endif

        @if (! $welcomed)
            {{-- Welcome --}}
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Welcome to QuoteFollow</h1>
            <p class="mt-2 text-sm text-gray-700">Let's get your business ready in a few minutes. You can skip anything optional and come back to it later.</p>
            <ol class="mt-4 space-y-1 text-sm text-gray-700">
                @foreach ($groups as $i => $group)<li>{{ $i + 1 }}. {{ $group['label'] }}</li>@endforeach
            </ol>
            <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                <button type="button" wire:click="start" wire:loading.attr="disabled" class="{{ $primary }}">Get Started</button>
                <button type="button" wire:click="skipAll" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
            </div>
            <p class="mt-3 text-xs text-gray-500">Skipping leaves everything as it is. A short checklist on your dashboard helps you finish later.</p>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::BusinessProfile)
            <h1 class="text-xl font-semibold text-gray-900">About your business</h1>
            <p class="mt-1 text-sm text-gray-600">Just the basics. You can change these any time in Settings.</p>
            <form wire:submit="saveBusiness" class="mt-5 space-y-4" novalidate>
                <div>
                    <label for="businessName" class="block text-sm font-medium text-gray-700">Business name</label>
                    <input id="businessName" type="text" wire:model="businessName" class="{{ $input }}" required autocomplete="organization">
                    @error('businessName') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="businessType" class="block text-sm font-medium text-gray-700">Business type</label>
                    <select id="businessType" wire:model="businessType" class="{{ $input }}" required>
                        <option value="">Choose one…</option>
                        @foreach ($businessTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('businessType') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="website" class="block text-sm font-medium text-gray-700">Website <span class="font-normal text-gray-500">(optional)</span></label>
                        <input id="website" type="text" wire:model="website" placeholder="dallashvac.com" class="{{ $input }}" autocomplete="url">
                        @error('website') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">Phone <span class="font-normal text-gray-500">(optional)</span></label>
                        <input id="phone" type="tel" wire:model="phone" placeholder="(214) 555-1234" class="{{ $input }}" autocomplete="tel">
                        @error('phone') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveBusiness" class="{{ $primary }}"><span wire:loading.remove wire:target="saveBusiness">Continue</span><span wire:loading wire:target="saveBusiness">Saving…</span></button>
                    <button type="button" wire:click="skipAll" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
                </div>
            </form>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::BusinessPreferences)
            <h1 class="text-xl font-semibold text-gray-900">Where are you located?</h1>
            <p class="mt-1 text-sm text-gray-600">We use this to send follow-ups at sensible local times. No full address needed.</p>
            <form wire:submit="saveLocation" class="mt-5 space-y-4" novalidate>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                        <select id="country" wire:model="country" class="{{ $input }}">@foreach ($countries as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                        @error('country') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="state" class="block text-sm font-medium text-gray-700">State</label>
                        <input id="state" type="text" wire:model.live.debounce.500ms="stateCode" maxlength="50" placeholder="TX" class="{{ $input }}" autocomplete="address-level1">
                        @error('stateCode') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="city" class="block text-sm font-medium text-gray-700">City</label>
                        <input id="city" type="text" wire:model="city" placeholder="Dallas" class="{{ $input }}" autocomplete="address-level2">
                        @error('city') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                    <select id="timezone" wire:model.live="timezone" class="{{ $input }}">
                        @foreach ($timezones as $zone => $label)<option value="{{ $zone }}">{{ $label }} ({{ $zone }})</option>@endforeach
                        @unless (array_key_exists($timezone, $timezones))<option value="{{ $timezone }}">{{ $timezone }}</option>@endunless
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Suggested from your state. Change it if it's wrong.</p>
                    @error('timezone') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveLocation" class="{{ $primary }}"><span wire:loading.remove wire:target="saveLocation">Continue</span><span wire:loading wire:target="saveLocation">Saving…</span></button>
                    <button type="button" wire:click="skip('business_preferences')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                </div>
            </form>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::EmailConnection)
            <h1 class="text-xl font-semibold text-gray-900">Connect your business email</h1>
            <p class="mt-1 text-sm text-gray-700">Connect the email address your business uses to communicate with customers.</p>
            <p class="mt-2 text-sm text-gray-600">QuoteFollow can send follow-ups and estimates to your customers from that address, and show their replies in your conversations. You approve anything automatic.</p>
            <p class="mt-4 text-sm" data-email-status>Status: <span class="font-medium text-gray-900">Not connected</span></p>
            <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                <a href="{{ route('settings.email', ['from' => 'onboarding']) }}" class="{{ $primary }}">Connect Email</a>
                <button type="button" wire:click="recheckEmail" wire:loading.attr="disabled" class="{{ $secondary }}">I've connected it — check again</button>
                <button type="button" wire:click="skip('email_connection')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip for now</button>
            </div>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FirstCustomer)
            <h1 class="text-xl font-semibold text-gray-900">Add a customer to try QuoteFollow</h1>
            <p class="mt-1 text-sm text-gray-600">Someone you've quoted or might quote. Only a name is required.</p>
            <form wire:submit="addCustomer" class="mt-5 space-y-4" novalidate>
                <div>
                    <label for="customerName" class="block text-sm font-medium text-gray-700">Name</label>
                    <input id="customerName" type="text" wire:model="customerName" class="{{ $input }}" placeholder="Jane Smith" autocomplete="off">
                    @error('customerName') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="customerEmail" class="block text-sm font-medium text-gray-700">Email</label>
                        <input id="customerEmail" type="email" wire:model="customerEmail" class="{{ $input }}" autocomplete="off">
                        @error('customerEmail') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="customerPhone" class="block text-sm font-medium text-gray-700">Phone</label>
                        <input id="customerPhone" type="tel" wire:model="customerPhone" class="{{ $input }}" autocomplete="off">
                        @error('customerPhone') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="customerCompany" class="block text-sm font-medium text-gray-700">Company <span class="font-normal text-gray-500">(optional)</span></label>
                    <input id="customerCompany" type="text" wire:model="customerCompany" class="{{ $input }}" autocomplete="off">
                    @error('customerCompany') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="addCustomer" class="{{ $primary }}"><span wire:loading.remove wire:target="addCustomer">Add Customer</span><span wire:loading wire:target="addCustomer">Adding…</span></button>
                    <button type="button" wire:click="skip('first_customer')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                </div>
            </form>
            <div class="mt-6 rounded-md bg-gray-50 p-4 text-sm" data-sample-offer>
                <p class="font-medium text-gray-900">Want to explore QuoteFollow first?</p>
                <p class="mt-1 text-gray-600">Create a clearly marked sample customer. It never receives real emails and you can delete it any time.</p>
                <button type="button" wire:click="createSampleCustomer" wire:loading.attr="disabled" class="{{ $secondary }} mt-3">Create Sample Customer</button>
            </div>

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FirstEstimate)
            <h1 class="text-xl font-semibold text-gray-900">Create your first estimate</h1>
            <p class="mt-1 text-sm text-gray-600">A quick draft. You'll review and send it from the estimate page, nothing is sent now.</p>
            @if ($customers->isEmpty())
                <p class="mt-4 text-sm text-gray-700">Add a customer first to create an estimate.</p>
                <div class="mt-4"><button type="button" wire:click="skip('first_estimate')" class="{{ $secondary }}">Skip</button></div>
            @else
                <form wire:submit="createEstimate" class="mt-5 space-y-4" novalidate>
                    <div>
                        <label for="estimateCustomerId" class="block text-sm font-medium text-gray-700">Customer</label>
                        <select id="estimateCustomerId" wire:model="estimateCustomerId" class="{{ $input }}">
                            <option value="">Choose a customer…</option>
                            @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->is_demo ? ' (sample)' : '' }}</option>@endforeach
                        </select>
                        @error('estimateCustomerId') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-[1fr_10rem]">
                        <div>
                            <label for="estimateTitle" class="block text-sm font-medium text-gray-700">What is the estimate for?</label>
                            <input id="estimateTitle" type="text" wire:model="estimateTitle" placeholder="AC installation" class="{{ $input }}" maxlength="200">
                            @error('estimateTitle') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="estimatePrice" class="block text-sm font-medium text-gray-700">Price ({{ $estimateDefaults['currency'] }})</label>
                            <input id="estimatePrice" type="text" inputmode="decimal" wire:model="estimatePrice" placeholder="2500" class="{{ $input }}">
                            @error('estimatePrice') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-xs text-gray-500" data-estimate-defaults>From {{ $estimateDefaults['business'] }} · {{ $estimateDefaults['currency'] }} · valid until {{ $organization->formatCalendarDate(\Carbon\CarbonImmutable::parse($estimateDefaults['valid_until'])) }}@if ($estimateDefaults['notes'] !== '') · default notes included @endif</p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="submit" wire:loading.attr="disabled" wire:target="createEstimate" class="{{ $primary }}"><span wire:loading.remove wire:target="createEstimate">Create Estimate</span><span wire:loading wire:target="createEstimate">Creating…</span></button>
                        <button type="button" wire:click="skip('first_estimate')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button>
                    </div>
                </form>
            @endif

        @elseif ($step === \App\Enums\Onboarding\OnboardingStep::FirstAutomation)
            @if ($review === null)
                <h1 class="text-xl font-semibold text-gray-900">Set up your first automation</h1>
                <p class="mt-1 text-sm text-gray-600">Suggested for {{ $businessTypes[$organization->business_type] ?? 'your business' }}. Nothing runs until you confirm it.</p>
                <ul role="list" class="mt-5 space-y-3" data-templates>
                    @foreach ($templates as $key => $template)
                        <li class="rounded-md border border-gray-200 p-4" data-template="{{ $key }}">
                            <p class="text-sm font-semibold text-gray-900">{{ $template['name'] }} @if ($loop->first)<span class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Recommended</span>@endif</p>
                            <p class="mt-1 text-sm text-gray-600">{{ $template['description'] }}</p>
                            <button type="button" wire:click="chooseTemplate('{{ $key }}')" wire:loading.attr="disabled" class="{{ $secondary }} mt-3">Use This Automation</button>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-5"><button type="button" wire:click="skip('first_automation')" wire:loading.attr="disabled" class="{{ $secondary }}">Skip</button></div>
            @else
                <h1 class="text-xl font-semibold text-gray-900">{{ $review['name'] }}</h1>
                <p class="mt-1 text-sm text-gray-600">Review exactly what this will do before turning it on.</p>
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-[8rem_1fr]" data-review>
                    <dt class="text-gray-500">When</dt><dd class="text-gray-900" data-review-trigger>{{ $review['trigger'] }}</dd>
                    @if ($review['delay'])<dt class="text-gray-500">Wait</dt><dd class="text-gray-900" data-review-delay>{{ $review['delay'] }}</dd>@endif
                    @if ($review['conditions'] !== [])<dt class="text-gray-500">If</dt><dd class="text-gray-900" data-review-conditions>{{ implode('; ', $review['conditions']) }}</dd>@endif
                    <dt class="text-gray-500">Then</dt><dd class="text-gray-900" data-review-actions>{{ implode('; ', $review['actions']) }}</dd>
                    @if ($review['sends_email'])
                        <dt class="text-gray-500">From</dt><dd class="text-gray-900" data-review-sender>{{ $review['sender'] ?? 'No email connected yet' }}</dd>
                        <dt class="text-gray-500">To</dt><dd class="text-gray-900">{{ $review['recipient'] }}</dd>
                    @endif
                </dl>
                @if ($review['sends_email'])
                    <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900" data-review-warning>
                        This automation emails your customers.
                        @if ($review['unattended']) Emails go out automatically.@else Each email waits for your approval until you allow automatic sending in Settings → Automation.@endif
                        Sample customers are never emailed.
                    </p>
                    @if ($review['sender'] === null)
                        <p class="mt-3 text-sm text-red-700" role="alert">Connect an email address first, or skip this step for now.</p>
                    @endif
                @endif
                <form wire:submit="activateAutomation" class="mt-5 space-y-4" novalidate>
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" wire:model="confirmed" class="mt-0.5 rounded border-gray-300 text-indigo-600"> <span>I've reviewed this and want to turn it on.</span>
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
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">You're ready to use QuoteFollow.</h1>
            <p class="mt-2 text-sm text-gray-600">Here's where things stand. Anything you skipped is on your dashboard checklist.</p>
            <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4" data-summary>
                @foreach (['customers' => 'Customers', 'estimates' => 'Estimates', 'automations' => 'Automations', 'follow_ups' => 'Follow-ups'] as $key => $label)
                    <div class="rounded-md bg-gray-50 p-3"><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="text-xl font-semibold text-gray-900" data-count="{{ $key }}">{{ $summary[$key] }}</dd></div>
                @endforeach
            </dl>
            @if ($sample)
                <p class="mt-4 text-sm text-gray-600" data-sample-note>You have a sample customer. <button type="button" wire:click="removeSampleData" class="font-medium text-indigo-700 underline">Delete sample data</button></p>
            @endif
            <p class="mt-4 text-sm text-gray-700">Working with a team? <a href="{{ route('settings.team') }}" class="font-medium text-indigo-700 underline">Invite your team</a> any time.</p>
            <div class="mt-6"><button type="button" wire:click="finish" wire:loading.attr="disabled" class="{{ $primary }}">Go to Dashboard</button></div>
        @endif

        @if ($sample && $step !== \App\Enums\Onboarding\OnboardingStep::Completed && $welcomed)
            <p class="mt-6 border-t border-gray-100 pt-3 text-xs text-gray-500">A sample customer exists (never emailed). <button type="button" wire:click="removeSampleData" class="font-medium text-indigo-700 underline">Delete sample data</button></p>
        @endif
    </section>
</div>

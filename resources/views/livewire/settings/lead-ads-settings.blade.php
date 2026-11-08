@php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
@php($badge = 'grid size-12 shrink-0 place-items-center rounded-2xl')
@php($label = 'block text-sm font-semibold text-slate-800')
@php($field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($primaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50')
@php($secondaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50 disabled:opacity-50')
<x-settings.shell title="Lead Ads" description="Leads from your Facebook and Instagram lead forms become customers in seconds, and your automations follow up at once.">
    @include('livewire.settings.partials.status')

    {{-- Connect --}}
    <section class="{{ $card }}" aria-labelledby="connect-heading">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-blue-50 text-[#1877F2]"><x-ui-icon name="megaphone" class="size-6" /></span>
                <div>
                    <h2 id="connect-heading" class="text-lg font-bold text-slate-900">Facebook Pages</h2>
                    <p class="text-sm text-slate-500">Connect the Page that runs your lead ads. Instagram lead ads come through the same Page.</p>
                </div>
            </div>
            @if ($facebook)
                <div x-data="metaLeadAdsLogin(@js($facebook))" class="flex flex-col items-end gap-1" data-lead-ads-connect>
                    <button type="button" x-on:click="start()" x-bind:disabled="busy" class="inline-flex items-center gap-2 rounded-xl bg-[#1877F2] px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-[#166fe0] disabled:opacity-60">
                        <svg viewBox="0 0 24 24" class="size-5 fill-current" aria-hidden="true"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.62 23.1 24 18.1 24 12.07"/></svg>
                        <span x-text="busy ? 'Connecting…' : '{{ $pages->isEmpty() ? 'Connect Facebook Page' : 'Add another Page' }}'">{{ $pages->isEmpty() ? 'Connect Facebook Page' : 'Add another Page' }}</span>
                    </button>
                    <p x-show="error" x-text="error" role="alert" class="max-w-xs text-right text-sm font-medium text-rose-700" x-cloak></p>
                </div>
            @endif
        </div>

        @unless ($facebook)
            <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200 ring-inset" data-lead-ads-unavailable>Lead Ads isn't set up on this server yet. Contact support to turn it on for your account.</p>
        @endunless

        @if ($choices !== [])
            <form wire:submit="connectPages" class="mt-5 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200" data-page-choices>
                <p class="font-semibold text-slate-900">Which Pages should send leads to QuoteFlow?</p>
                <ul role="list" class="mt-3 space-y-2">
                    @foreach ($choices as $choice)
                        <li>
                            <label @class(['flex items-center gap-3 rounded-lg bg-white px-3 py-2.5 text-sm ring-1 ring-slate-200', 'opacity-60' => $choice['connected_elsewhere']])>
                                <input type="checkbox" wire:model="selected" value="{{ $choice['id'] }}" @disabled($choice['connected_elsewhere'])>
                                <span class="font-semibold text-slate-900">{{ $choice['name'] }}</span>
                                @if ($choice['connected_elsewhere'])<span class="text-xs text-slate-500">Connected to another business</span>@endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" class="{{ $primaryButton }}">Connect selected</button>
                    <button type="button" wire:click="cancelChoice" class="{{ $secondaryButton }}">Cancel</button>
                </div>
            </form>
        @endif

        @if ($pages->isNotEmpty())
            <ul role="list" class="mt-5 divide-y divide-slate-100" data-connected-pages>
                @foreach ($pages as $page)
                    <li class="py-4" wire:key="page-{{ $page->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 font-semibold text-slate-900">
                                    {{ $page->page_name }}
                                    @if ($page->isActive())
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 ring-inset"><span class="size-1.5 rounded-full bg-current"></span>Receiving leads</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200 ring-inset">Needs reconnecting</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-sm text-slate-500">{{ $page->leads_count }} {{ \Illuminate\Support\Str::plural('lead', $page->leads_count) }} · Last lead {{ $page->last_lead_at ? $page->last_lead_at->diffForHumans() : 'not yet' }}</p>
                                @if ($page->last_error)<p class="mt-1 text-sm text-rose-700">{{ $page->last_error }}</p>@endif
                            </div>
                            <button type="button" wire:click="disconnect({{ $page->id }})" wire:confirm="Disconnect {{ $page->page_name }}? New leads from its forms stop coming in. Customers already added stay." class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">Disconnect</button>
                        </div>
                        <form wire:submit="savePage({{ $page->id }})" class="mt-3 grid gap-3 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200 sm:grid-cols-[minmax(0,16rem)_minmax(0,1fr)_auto] sm:items-end">
                            <div>
                                <label for="assign-{{ $page->id }}" class="{{ $label }}">New leads are handled by</label>
                                <select id="assign-{{ $page->id }}" wire:model="pageSettings.{{ $page->id }}.assign_to" class="{{ $field }}">
                                    <option value="">Nobody yet</option>
                                    @foreach ($team as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-500">The owner and managers are always told.</p>
                            </div>
                            <label class="flex items-start gap-2.5 text-sm text-slate-700">
                                <input type="checkbox" wire:model="pageSettings.{{ $page->id }}.whatsapp_consent" class="mt-0.5">
                                <span><span class="font-semibold text-slate-900">My form says we'll contact people on WhatsApp</span><br><span class="text-slate-500">New leads with a phone number count as agreeing to WhatsApp messages, so automations can message them there.</span></span>
                            </label>
                            <button type="submit" class="{{ $primaryButton }}">Save</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @elseif ($facebook && $choices === [])
            <div class="mt-5 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No Page connected yet.</div>
        @endif
    </section>

    {{-- What happens --}}
    <section class="{{ $card }}" aria-labelledby="how-heading">
        <h2 id="how-heading" class="text-lg font-bold text-slate-900">What happens with each lead</h2>
        <ol class="mt-4 grid gap-3 text-sm text-slate-700 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['user-plus', 'Added as a customer', 'Name, email, phone and every answer from the form. Someone already in your customers is updated, not duplicated.'],
                ['bolt', 'Automations start', 'Your “Customer created” automations run straight away, e.g. a welcome message and follow-ups.'],
                ['bell', 'Your team is told', 'The person handling it, the owner and managers get a notification.'],
                ['arrow-path', 'Nothing is missed', 'QuoteFlow also checks your forms every 15 minutes in case Facebook didn\'t send a lead.'],
            ] as [$icon, $title, $text])
                <li class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <span class="grid size-9 place-items-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 ring-inset"><x-ui-icon :name="$icon" class="size-4" /></span>
                    <p class="mt-2 font-semibold text-slate-900">{{ $title }}</p>
                    <p class="mt-0.5 text-slate-600">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
        @include('livewire.settings.partials.setup-help', ['topic' => 'Facebook lead ads'])
    </section>

    {{-- Recent leads --}}
    @if ($leads->isNotEmpty())
        <section class="{{ $card }}" aria-labelledby="recent-heading">
            <h2 id="recent-heading" class="text-lg font-bold text-slate-900">Recent leads</h2>
            <ul role="list" class="mt-3 divide-y divide-slate-100 text-sm" data-recent-leads>
                @foreach ($leads as $lead)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            @if ($lead->customer)
                                <a href="{{ route('customers.show', $lead->customer_id) }}" wire:navigate class="font-semibold text-slate-900 hover:underline">{{ $lead->customer->name }}</a>
                            @else
                                <span class="font-semibold text-slate-500">Lead {{ $lead->leadgen_id }}</span>
                            @endif
                            <p class="text-xs text-slate-500">{{ $lead->platformLabel() }}{{ $lead->form_name ? ' · '.$lead->form_name : '' }}{{ $lead->page ? ' · '.$lead->page->page_name : '' }} · {{ $organization->formatDateTime($lead->created_at) }}</p>
                            @if ($lead->error)<p class="text-xs text-rose-700">{{ $lead->error }}</p>@endif
                        </div>
                        <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $lead->status === 'imported',
                            'bg-sky-50 text-sky-700 ring-sky-200' => $lead->status === 'matched',
                            'bg-amber-50 text-amber-800 ring-amber-200' => $lead->status === 'pending',
                            'bg-rose-50 text-rose-700 ring-rose-200' => $lead->status === 'failed'])>{{ ['imported' => 'New customer', 'matched' => 'Existing customer', 'pending' => 'Fetching', 'failed' => 'Not added'][$lead->status] ?? $lead->status }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-settings.shell>

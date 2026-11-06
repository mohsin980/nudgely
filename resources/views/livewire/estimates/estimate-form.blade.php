<div class="space-y-6 pb-24 sm:pb-0">
    @php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
    @php($heading = 'text-lg font-bold text-slate-900')
    @php($badge = 'grid size-11 shrink-0 place-items-center rounded-full bg-violet-100 text-violet-600')
    @php($field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    @php($totals = $this->totals)

    <div class="space-y-1">
        <a href="{{ $estimateId ? route('estimates.show', $estimateId) : route('estimates.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-semibold text-violet-700 hover:text-violet-800"><x-ui-icon name="arrow-left" class="size-4 stroke-2" /> {{ $estimateId ? 'Back to estimate' : 'Back to Estimates' }}</a>
        <h1 class="pt-1 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-[34px]">{{ $estimateId ? 'Edit draft estimate' : 'New estimate' }}</h1>
        <p class="text-[15px] text-slate-500">Create a professional estimate and send it to your customer.</p>
    </div>

    @if (session('estimate-status'))
        <div role="status" class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('estimate-status') }}</div>
    @endif
    @error('form') <div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div> @enderror

    <form wire:submit="save" class="space-y-6" aria-label="Estimate">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <div class="min-w-0 space-y-6">
                {{-- Customer --}}
                <section aria-labelledby="customer-heading" class="{{ $card }} space-y-4">
                    <div class="flex items-center gap-3.5">
                        <span class="{{ $badge }}"><x-ui-icon name="user" class="size-5 stroke-2" /></span>
                        <div><h2 id="customer-heading" class="{{ $heading }}">Customer</h2><p class="text-sm text-slate-500">Find or select a customer for this estimate.</p></div>
                    </div>
                    @if ($this->customer)
                        @php($initials = collect(preg_split('/\s+/', trim($this->customer->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''))
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-violet-50/50 p-3.5 ring-1 ring-violet-100" data-selected-customer>
                            <div class="flex min-w-0 items-center gap-3">
                            <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-xl bg-linear-to-br from-violet-600 to-violet-400 text-sm font-bold text-white shadow-sm">{{ $initials }}</span>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $this->customer->name }}</p>
                                <p class="truncate text-sm text-slate-500">{{ $this->customer->email }}</p>
                                @if ($this->conversation)
                                    <p class="text-xs text-slate-500">Linked to conversation: {{ $this->conversation->subject ?? '(no subject)' }}</p>
                                @endif
                            </div>
                            </div>
                            <button type="button" wire:click="clearCustomer" class="inline-flex items-center gap-1.5 rounded-xl bg-violet-100 px-4 py-2 text-sm font-semibold text-violet-700 transition hover:bg-violet-200"><x-ui-icon name="arrows-right-left" class="size-4 stroke-2" /> Change</button>
                        </div>
                    @else
                        <div>
                            <label for="customer-search" class="sr-only">Find a customer</label>
                            <div class="relative">
                                <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                                <input id="customer-search" type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="Search customer by name, email or phone…" autocomplete="off" class="{{ $field }} mt-0! pl-11">
                            </div>
                        </div>
                        <ul role="list" class="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200" aria-label="Matching customers">
                            @forelse ($this->customerMatches as $match)
                                <li wire:key="match-{{ $match->id }}">
                                    <button type="button" wire:click="selectCustomer({{ $match->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-inset">
                                        <span class="font-medium text-slate-900">{{ $match->name }}</span> <span class="text-slate-500">{{ $match->email }}</span>
                                    </button>
                                </li>
                            @empty
                                <li class="px-3 py-2 text-sm text-slate-500">No customers found. <a href="{{ route('customers.create') }}" wire:navigate class="font-medium text-violet-700 hover:underline">Add a customer</a> first.</li>
                            @endforelse
                        </ul>
                    @endif
                    @error('customerId') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                </section>

                {{-- Quote details --}}
                <section aria-labelledby="details-heading" class="{{ $card }} space-y-3">
                    <div class="flex items-center gap-3.5 pb-1">
                        <span class="{{ $badge }}"><x-ui-icon name="list" class="size-5 stroke-2" /></span>
                        <div><h2 id="details-heading" class="{{ $heading }}">Quote details</h2><p class="text-sm text-slate-500">Shown to the customer on the estimate.</p></div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2"><label for="estimate-title" class="block text-[15px] font-semibold text-slate-800">Title <span class="text-red-500" aria-hidden="true">*</span></label>
                            <input id="estimate-title" type="text" wire:model="title" maxlength="200" placeholder="AC Installation" class="{{ $field }}">
                            @error('title') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div><label for="estimate-valid-until" class="block text-[15px] font-semibold text-slate-800">Valid until</label>
                            <input id="estimate-valid-until" type="date" wire:model="validUntil" class="{{ $field }}">
                            @error('validUntil') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div class="sm:col-span-3"><label for="estimate-notes" class="block text-[15px] font-semibold text-slate-800">Notes <span class="font-normal text-slate-500">(shown to the customer)</span></label>
                            <textarea id="estimate-notes" wire:model="notes" rows="3" maxlength="5000" placeholder="Includes removal of the old unit and a 10-year parts warranty." class="{{ $field }}"></textarea>
                            @error('notes') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                    </div>
                </section>

                {{-- Items --}}
                <section aria-labelledby="items-heading" class="{{ $card }} space-y-3">
                    <div class="flex flex-wrap items-center gap-3.5 pb-1">
                        <span class="{{ $badge }}"><x-ui-icon name="cube" class="size-5 stroke-2" /></span>
                        <div class="flex-1"><h2 id="items-heading" class="{{ $heading }}">Items</h2><p class="text-sm text-slate-500">Each line is one part of the work. At least one is needed to send.</p></div>
                        <button type="button" wire:click="addItem" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-violet-700 ring-1 ring-violet-300 ring-inset transition hover:bg-violet-50"><x-ui-icon name="plus" class="size-4 stroke-2" /> Add Item</button>
                    </div>
                    @error('items') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="hidden grid-cols-[minmax(0,1fr)_5.5rem_8rem_7rem_3rem] gap-3 rounded-t-xl bg-slate-50 px-3 py-2.5 text-[13px] font-semibold text-slate-600 sm:grid" aria-hidden="true">
                        <span>Description</span><span>Qty</span><span>Unit price ($)</span><span class="text-right">Amount</span><span></span>
                    </div>
                    <ol role="list" class="space-y-3 sm:space-y-2" data-section="items">
                        @foreach ($items as $i => $item)
                            <li wire:key="item-{{ $i }}" class="grid grid-cols-3 gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_5.5rem_8rem_7rem_3rem] sm:items-start sm:gap-3 sm:border-0 sm:px-3 sm:py-0">
                                <div class="col-span-3 sm:col-span-1">
                                    <label for="item-{{ $i }}-description" class="block text-xs font-medium text-slate-500 sm:sr-only">Description</label>
                                    <input id="item-{{ $i }}-description" type="text" wire:model="items.{{ $i }}.description" maxlength="500" placeholder="AC Installation" class="{{ $field }} sm:mt-0">
                                    @error("items.$i.description") <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="item-{{ $i }}-quantity" class="block text-xs font-medium text-slate-500 sm:sr-only">Qty</label>
                                    <input id="item-{{ $i }}-quantity" type="text" inputmode="decimal" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" class="{{ $field }} sm:mt-0">
                                    @error("items.$i.quantity") <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="item-{{ $i }}-price" class="block text-xs font-medium text-slate-500 sm:sr-only">Unit price ($)</label>
                                    <input id="item-{{ $i }}-price" type="text" inputmode="decimal" wire:model.live.debounce.400ms="items.{{ $i }}.unit_price" placeholder="0.00" class="{{ $field }} sm:mt-0">
                                    @error("items.$i.unit_price") <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div class="text-right">
                                    <span class="block text-xs font-medium text-slate-500 sm:sr-only">Amount</span>
                                    <span class="mt-2 block rounded-xl bg-slate-50 px-3 py-2.5 text-[15px] font-semibold text-slate-900 sm:mt-0" data-line-amount>{{ $this->lineAmount($i) ?? '—' }}</span>
                                </div>
                                <div class="col-span-3 text-right sm:col-span-1">
                                    <button type="button" wire:click="removeItem({{ $i }})" title="Remove item" class="inline-grid size-10 place-items-center rounded-xl text-red-500 transition hover:bg-red-50 hover:text-red-700 sm:mt-0.5"><x-ui-icon name="trash" class="size-5" /><span class="sr-only">Remove item {{ $i + 1 }}</span></button>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>

                {{-- Discount and tax --}}
                <section aria-labelledby="totals-heading" class="{{ $card }} space-y-4">
                    <div class="flex items-center gap-3.5">
                        <span class="{{ $badge }}"><x-ui-icon name="dollar" class="size-5 stroke-2" /></span>
                        <div><h2 id="totals-heading" class="{{ $heading }}">Discount and tax</h2><p class="text-sm text-slate-500">Leave blank if none applies.</p></div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label for="discount-type" class="block text-sm font-medium text-slate-700">Discount</label>
                            <select id="discount-type" wire:model.live="discountType" class="{{ $field }}">
                                <option value="">No discount</option>
                                @foreach ($discountTypes as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                            </select></div>
                        @if ($discountType !== '')
                            <div><label for="discount-value" class="block text-sm font-medium text-slate-700">{{ $discountType === 'percent' ? 'Discount (%)' : 'Discount ($)' }}</label>
                                <input id="discount-value" type="text" inputmode="decimal" wire:model.live.debounce.400ms="discountValue" class="{{ $field }}">
                                @error('discountValue') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        @endif
                        <div><label for="tax-rate" class="block text-sm font-medium text-slate-700">Tax rate (%)</label>
                            <input id="tax-rate" type="text" inputmode="decimal" wire:model.live.debounce.400ms="taxRate" placeholder="0" class="{{ $field }}">
                            @error('taxRate') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                    </div>
                </section>
            </div>

            {{-- Summary: stays in view while the form is long --}}
            <aside class="min-w-0 lg:sticky lg:top-6">
                <section aria-labelledby="summary-heading" class="overflow-hidden rounded-2xl bg-white shadow-[0_14px_40px_-16px_rgba(76,29,149,0.35)] ring-1 ring-slate-200/70">
                    <div class="relative overflow-hidden bg-linear-to-br from-violet-700 via-violet-600 to-fuchsia-500 px-5 pt-5 pb-9 text-white">
                        <div aria-hidden="true" class="absolute -top-10 -right-8 size-36 rounded-full bg-white/15 blur-2xl"></div>
                        <div class="relative flex items-center gap-3.5">
                            <span class="grid size-12 place-items-center rounded-xl bg-white/20 ring-1 ring-white/30 backdrop-blur"><x-ui-icon name="document" class="size-6" /></span>
                            <div><h2 id="summary-heading" class="text-lg font-bold">Estimate Summary</h2><p class="text-sm text-violet-100">Updates as you type.</p></div>
                        </div>
                        <svg aria-hidden="true" class="absolute inset-x-0 -bottom-px h-6 w-full text-white" viewBox="0 0 320 24" preserveAspectRatio="none"><path fill="currentColor" d="M0 24V14C70 0 140 26 210 14S300 2 320 8v16Z"/></svg>
                    </div>
                    <div class="px-5 pt-1 pb-5">
                        <p class="text-[34px] leading-tight font-extrabold tracking-tight text-slate-900" data-total>{{ $this->money($totals->total) }}</p>
                        <p class="text-[15px] text-slate-500">Total amount</p>
                        <dl class="mt-4 grid grid-cols-[1fr_auto] gap-y-3 border-t border-slate-100 pt-4 text-[15px]" data-section="totals">
                            <dt class="font-medium text-slate-700">Subtotal</dt><dd class="pl-6 text-right font-semibold text-slate-900" data-subtotal>{{ $this->money($totals->subtotal) }}</dd>
                            <dt class="font-medium text-slate-700">Discount</dt><dd class="pl-6 text-right font-semibold text-slate-900">{{ $totals->discount ? '−'.$this->money($totals->discount) : $this->money(0) }}</dd>
                            <dt class="font-medium text-slate-700">Tax</dt><dd class="pl-6 text-right font-semibold text-slate-900">{{ $this->money($totals->tax) }}</dd>
                        </dl>
                        <p class="mt-4 text-xs text-slate-500">Totals are recalculated when you save.</p>
                    </div>
                </section>
            </aside>
        </div>

        {{-- Actions: fixed on phones, sticky at the bottom on larger screens --}}
        <div class="fixed inset-x-0 bottom-0 z-10 flex items-center justify-between gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:sticky sm:bottom-4 sm:rounded-2xl sm:border sm:border-white sm:bg-white sm:px-4 sm:py-3 sm:shadow-[0_14px_40px_-14px_rgba(76,29,149,0.3)] sm:ring-1 sm:ring-slate-200/70 sm:backdrop-blur-none">
            <p class="text-sm font-semibold whitespace-nowrap text-slate-900 sm:hidden">Total {{ $this->money($totals->total) }}</p>
            <p class="hidden items-center gap-2 rounded-xl bg-violet-50 px-3.5 py-2.5 text-sm font-medium text-violet-800 sm:flex"><x-ui-icon name="info" class="size-5 shrink-0 stroke-2 text-violet-600" /> Draft: Nothing is sent until you choose Save &amp; Send.</p>
            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-white px-5 py-2.5 whitespace-nowrap text-[15px] font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50 disabled:opacity-50">Save Draft</button>
                <button type="button" wire:click="saveAndSend" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 whitespace-nowrap text-[15px] font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:opacity-50"><x-ui-icon name="paper-airplane" class="size-5" wire:loading.remove wire:target="saveAndSend" /><span wire:loading.remove wire:target="saveAndSend">Save &amp; Send</span><span wire:loading wire:target="saveAndSend">Sending…</span></button>
            </div>
        </div>
    </form>
</div>

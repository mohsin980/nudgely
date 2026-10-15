<div class="space-y-6 pb-24 sm:pb-0">
    @php($card = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6')
    @php($heading = 'text-base font-semibold text-slate-900')
    @php($field = 'mt-1.5 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    @php($totals = $this->totals)

    <div class="space-y-1">
        <a href="{{ $estimateId ? route('estimates.show', $estimateId) : route('estimates.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; {{ $estimateId ? 'Back to estimate' : 'Estimates' }}</a>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $estimateId ? 'Edit draft estimate' : 'New estimate' }}</h1>
    </div>

    @if (session('estimate-status'))
        <div role="status" class="rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{{ session('estimate-status') }}</div>
    @endif
    @error('form') <div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div> @enderror

    <form wire:submit="save" class="space-y-6" aria-label="Estimate">
        {{-- Customer --}}
        <section aria-labelledby="customer-heading" class="{{ $card }} space-y-3">
            <h2 id="customer-heading" class="{{ $heading }}">Customer</h2>
            @if ($this->customer)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 p-3" data-selected-customer>
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ $this->customer->name }}</p>
                        <p class="truncate text-sm text-slate-500">{{ $this->customer->email }}</p>
                        @if ($this->conversation)
                            <p class="text-xs text-slate-500">Linked to conversation: {{ $this->conversation->subject ?? '(no subject)' }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="clearCustomer" class="text-sm font-medium text-violet-700 hover:underline">Change</button>
                </div>
            @else
                <div>
                    <label for="customer-search" class="block text-sm font-medium text-slate-700">Find a customer</label>
                    <input id="customer-search" type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="Name, email or phone" autocomplete="off" class="{{ $field }}">
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
            <h2 id="details-heading" class="{{ $heading }}">Quote details</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2"><label for="estimate-title" class="block text-sm font-medium text-slate-700">Title</label>
                    <input id="estimate-title" type="text" wire:model="title" maxlength="200" placeholder="AC Installation" class="{{ $field }}">
                    @error('title') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="estimate-valid-until" class="block text-sm font-medium text-slate-700">Valid until</label>
                    <input id="estimate-valid-until" type="date" wire:model="validUntil" class="{{ $field }}">
                    @error('validUntil') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-3"><label for="estimate-notes" class="block text-sm font-medium text-slate-700">Notes <span class="font-normal text-slate-500">(shown to the customer)</span></label>
                    <textarea id="estimate-notes" wire:model="notes" rows="3" maxlength="5000" placeholder="Includes removal of the old unit and a 10-year parts warranty." class="{{ $field }}"></textarea>
                    @error('notes') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            </div>
        </section>

        {{-- Items --}}
        <section aria-labelledby="items-heading" class="{{ $card }} space-y-3">
            <h2 id="items-heading" class="{{ $heading }}">Items</h2>
            @error('items') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            <div class="hidden grid-cols-[minmax(0,1fr)_5.5rem_8rem_7rem_4.5rem] gap-2 text-xs font-medium text-slate-500 sm:grid" aria-hidden="true">
                <span>Description</span><span>Qty</span><span>Unit price ($)</span><span class="text-right">Amount</span><span></span>
            </div>
            <ol role="list" class="space-y-3 sm:space-y-2" data-section="items">
                @foreach ($items as $i => $item)
                    <li wire:key="item-{{ $i }}" class="grid grid-cols-3 gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_5.5rem_8rem_7rem_4.5rem] sm:items-start sm:border-0 sm:p-0">
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
                            <span class="mt-2 block text-sm font-medium text-slate-900 sm:mt-1.5" data-line-amount>{{ $this->lineAmount($i) ?? '—' }}</span>
                        </div>
                        <div class="col-span-3 text-right sm:col-span-1">
                            <button type="button" wire:click="removeItem({{ $i }})" class="text-sm font-medium text-red-700 hover:underline sm:mt-1.5">Remove<span class="sr-only"> item {{ $i + 1 }}</span></button>
                        </div>
                    </li>
                @endforeach
            </ol>
            <button type="button" wire:click="addItem" class="rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50">+ Add Item</button>
        </section>

        {{-- Totals --}}
        <section aria-labelledby="totals-heading" class="{{ $card }} space-y-4">
            <h2 id="totals-heading" class="{{ $heading }}">Totals</h2>
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
            <dl class="ml-auto grid max-w-xs grid-cols-[1fr_auto] gap-y-1 text-sm" data-section="totals">
                <dt class="text-slate-500">Subtotal</dt><dd class="pl-6 text-right text-slate-900" data-subtotal>{{ $this->money($totals->subtotal) }}</dd>
                <dt class="text-slate-500">Discount</dt><dd class="pl-6 text-right text-slate-900">{{ $totals->discount ? '−'.$this->money($totals->discount) : $this->money(0) }}</dd>
                <dt class="text-slate-500">Tax</dt><dd class="pl-6 text-right text-slate-900">{{ $this->money($totals->tax) }}</dd>
                <dt class="border-t border-slate-200 pt-2 text-base font-semibold text-slate-900">Total</dt><dd class="border-t border-slate-200 pt-2 pl-6 text-right text-xl font-bold text-slate-900" data-total>{{ $this->money($totals->total) }}</dd>
            </dl>
            <p class="text-xs text-slate-500">Totals are recalculated when you save.</p>
        </section>

        {{-- Actions: stay on screen on phones --}}
        <div class="fixed inset-x-0 bottom-0 z-10 flex items-center justify-between gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:justify-end sm:border-0 sm:bg-transparent sm:p-0">
            <p class="text-sm font-semibold whitespace-nowrap text-slate-900 sm:hidden">Total {{ $this->money($totals->total) }}</p>
            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-white px-4 py-2.5 whitespace-nowrap text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 disabled:opacity-50">Save Draft</button>
                <button type="button" wire:click="saveAndSend" wire:loading.attr="disabled" class="rounded-xl bg-violet-600 px-4 py-2.5 whitespace-nowrap text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700 disabled:opacity-50"><span wire:loading.remove wire:target="saveAndSend">Save &amp; Send</span><span wire:loading wire:target="saveAndSend">Sending…</span></button>
            </div>
        </div>
    </form>
</div>

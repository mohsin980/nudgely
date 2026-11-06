<div @class(['space-y-6', 'max-w-2xl' => $customerId, 'max-w-5xl' => ! $customerId])>
    <div>
        <a href="{{ $customerId ? route('customers.show', $customerId) : route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-semibold text-violet-700 hover:text-violet-900"><x-ui-icon name="arrow-right" class="size-4 rotate-180" />{{ $customerId ? 'Back to customer' : 'Back to Customers' }}</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $customerId ? 'Edit customer' : 'Add customer' }}</h1>
        <p class="mt-1 text-[15px] text-slate-500">{{ $customerId ? 'Update the customer\'s details. Changes are saved when you press Save changes.' : 'Enter the customer\'s details below. You can add more information later.' }}</p>
    </div>
    <div @class(['grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_18rem]' => ! $customerId])>
        @include('livewire.customers.partials.customer-fields', ['withStatus' => $customerId !== null])
        @unless ($customerId)
            <aside class="relative overflow-hidden rounded-2xl border border-violet-100 bg-linear-to-b from-violet-50 to-white p-6 text-sm text-slate-700 lg:self-start" aria-labelledby="customer-help-title">
                <span aria-hidden="true" class="pointer-events-none absolute -top-10 -right-10 size-28 rounded-full bg-violet-100/70"></span>
                <span class="relative grid size-12 place-items-center rounded-full bg-violet-100 text-violet-600 ring-4 ring-white"><x-ui-icon name="envelope" class="size-6" /></span>
                <h2 id="customer-help-title" class="relative mt-4 text-base font-bold text-slate-900">Before you save</h2>
                <div class="mt-4 border-t border-violet-100 pt-4">
                    <p class="font-semibold text-slate-900">Email is needed for follow-ups</p>
                    <p class="mt-1 leading-relaxed text-slate-600">Customers without an email address can't be sent estimates or follow-up emails.</p>
                </div>
                <div class="mt-4 border-t border-violet-100 pt-4">
                    <p class="flex items-center gap-2 font-semibold text-slate-900"><x-ui-icon name="phone" class="size-4 text-violet-600" />Phone is optional</p>
                    <p class="mt-1 leading-relaxed text-slate-600">Add a phone number if you have it. It's saved for your reference.</p>
                </div>
            </aside>
        @endunless
    </div>
</div>

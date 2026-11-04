<div @class(['space-y-6', 'max-w-2xl' => $customerId, 'max-w-5xl' => ! $customerId])>
    <div>
        <a href="{{ $customerId ? route('customers.show', $customerId) : route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-900">&larr; {{ $customerId ? 'Customer' : 'Customers' }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ $customerId ? 'Edit customer' : 'Add customer' }}</h1>
        <p class="mt-1 text-[15px] text-slate-500">Enter the customer's details below. You can add more information later.</p>
    </div>
    <div @class(['grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_18rem]' => ! $customerId])>
        @include('livewire.customers.partials.customer-fields', ['withStatus' => $customerId !== null])
        @unless ($customerId)
            <aside class="rounded-2xl border border-violet-100 bg-violet-50/60 p-5 text-sm text-slate-700 lg:self-start" aria-labelledby="customer-help-title">
                <h2 id="customer-help-title" class="font-semibold text-slate-900">Before you save</h2>
                <div class="mt-3 rounded-xl bg-white p-4 ring-1 ring-violet-100">
                    <p class="font-semibold text-violet-800">Email is needed for follow-ups</p>
                    <p class="mt-1 text-slate-600">Customers without an email address can't be sent estimates or follow-up emails.</p>
                </div>
                <p class="mt-4 text-slate-600">Phone is saved on the customer for your reference.</p>
            </aside>
        @endunless
    </div>
</div>

<div class="max-w-2xl space-y-6">
    <div>
        <a href="{{ $customerId ? route('customers.show', $customerId) : route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-900">&larr; {{ $customerId ? 'Customer' : 'Customers' }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ $customerId ? 'Edit customer' : 'Add customer' }}</h1>
        <p class="mt-1 text-[15px] text-slate-500">Enter the customer's details below. You can add more information later.</p>
    </div>
    @include('livewire.customers.partials.customer-fields', ['withStatus' => $customerId !== null])
</div>

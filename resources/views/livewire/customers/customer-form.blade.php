<div class="max-w-2xl space-y-6">
    <div>
        <a href="{{ $customerId ? route('customers.show', $customerId) : route('customers.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; {{ $customerId ? 'Customer' : 'Customers' }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">{{ $customerId ? 'Edit customer' : 'Add customer' }}</h1>
    </div>
    @include('livewire.customers.partials.customer-fields', ['withStatus' => $customerId !== null])
</div>

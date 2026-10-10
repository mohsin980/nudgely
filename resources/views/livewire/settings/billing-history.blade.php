<x-settings.shell title="Invoices" description="Your past invoices. Invoices open on our payment provider's secure page.">
    @include('livewire.settings.partials.billing-tabs')

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm" data-section="invoices">
        @if ($error)
            <p class="p-4 text-sm text-gray-600 sm:p-5">{{ $error }}</p>
        @elseif ($invoices === [])
            <p class="p-4 text-sm text-gray-600 sm:p-5">{{ $hasBillingAccount ? 'No invoices yet.' : 'No invoices yet. They appear here after your first payment.' }}</p>
        @else
            <ul role="list" class="divide-y divide-gray-100">
                @foreach ($invoices as $invoice)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 p-4 sm:px-5" data-invoice="{{ $invoice->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $invoice->number ?? 'Invoice' }}</p>
                            <p class="text-xs text-gray-500">{{ $organization->formatDate($invoice->date) }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-gray-900">{{ \App\Support\Money::format($invoice->amountCents, $invoice->currency) }}</span>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800">{{ ucfirst($invoice->status) }}</span>
                            @if ($invoice->viewUrl)
                                <a href="{{ $invoice->viewUrl }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-violet-700 hover:underline">View invoice</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-settings.shell>

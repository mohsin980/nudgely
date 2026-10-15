{{-- Section tabs for the billing pages; scrolls sideways on narrow screens. --}}
<nav aria-label="Billing sections" class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
    <ul class="flex min-w-max gap-1 border-b border-slate-200">
        @foreach (['settings.billing' => 'Plan', 'settings.billing.plans' => 'Plans', 'settings.billing.usage' => 'Usage', 'settings.billing.history' => 'Invoices'] as $route => $label)
            @php($active = request()->routeIs($route))
            <li>
                <a href="{{ route($route) }}" wire:navigate @if ($active) aria-current="page" @endif
                   @class(['-mb-px block border-b-2 px-3 py-2 text-sm whitespace-nowrap', 'border-emerald-600 font-medium text-emerald-700' => $active, 'border-transparent text-slate-500 hover:text-slate-900' => ! $active])>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>

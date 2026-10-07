@php($card = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm')
@php($secondary = 'inline-flex justify-center rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 disabled:opacity-50')
<x-settings.shell title="Billing" description="Your plan and how much of it you're using. Payments and cards are handled securely by our payment provider.">
    @include('livewire.settings.partials.status')

    <section aria-labelledby="plan-heading" class="{{ $card }}" data-section="current-plan">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="plan-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Current plan</h2>
                <p class="mt-2 text-xl font-semibold text-gray-900">{{ $plan->name }} <span class="text-sm font-normal text-gray-600">· {{ $plan->priceLabel() }}</span></p>
            </div>
            @if ($organization->billing_customer_id)
                <button type="button" wire:click="openPortal" wire:loading.attr="disabled" class="{{ $secondary }}">Manage payment & invoices</button>
            @endif
        </div>
        @if ($subscription)
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Status</dt><dd class="font-medium text-gray-900" data-status>{{ $subscription->status->label() }}</dd></div>
                @if ($subscription->trial_ends_at && $subscription->onTrial())<div><dt class="text-gray-500">Trial ends</dt><dd class="text-gray-900">{{ $organization->formatDate($subscription->trial_ends_at) }}</dd></div>@endif
                @if ($subscription->current_period_end)<div><dt class="text-gray-500">Current period ends</dt><dd class="text-gray-900">{{ $organization->formatDate($subscription->current_period_end) }}</dd></div>@endif
            </dl>
            <p class="mt-3 text-sm text-gray-800" data-notice>{{ $notice }}</p>
            @unless ($subscription->grantsAccess())
                <p class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900">This subscription doesn't currently give access to its plan, so the {{ $plan->name }} plan limits apply. Update your payment method to continue.</p>
            @endunless
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($subscription->cancel_at_period_end)
                    <button type="button" wire:click="resumeSubscription" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500">Resume subscription</button>
                @else
                    <button type="button" wire:click="cancelSubscription" wire:confirm="Cancel your subscription? It stays active until the end of the current period, then you move to the Free plan. Nothing is deleted." class="rounded-md px-3 py-1.5 text-sm font-medium text-red-700 hover:underline">Cancel subscription</button>
                @endif
            </div>
        @else
            <p class="mt-2 text-sm text-gray-600">No subscription — you're on the {{ $plan->name }} plan.@if ($trialDays > 0) Paid plans start with a {{ $trialDays }}-day free trial.@endif</p>
        @endif
    </section>

    <section aria-labelledby="usage-heading" class="{{ $card }}">
        <h2 id="usage-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Usage</h2>
        <p class="mt-1 text-xs text-gray-500">Monthly limits: {{ $organization->formatDate($periodStart) }} – {{ $organization->formatDate($periodEnd) }}</p>
        <ul role="list" class="mt-3 space-y-3" data-section="usage">
            @foreach ($rows as $key => $row)
                @php($percent = $row['limit'] ? min(100, (int) round($row['used'] / max(1, $row['limit']) * 100)) : 0)
                <li data-limit="{{ $key }}">
                    <div class="flex justify-between text-sm"><span class="text-gray-800">{{ $row['label'] }}</span><span @class(['font-medium', 'text-red-700' => $row['over'], 'text-gray-900' => ! $row['over']])>{{ number_format($row['used']) }} / {{ $row['limit'] === null ? 'Unlimited' : number_format($row['limit']) }}</span></div>
                    @if ($row['limit'])
                        <div class="mt-1 h-1.5 rounded-full bg-gray-100" aria-hidden="true"><div @class(['h-1.5 rounded-full', 'bg-red-500' => $percent >= 100, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-indigo-500' => $percent < 80]) style="width: {{ $percent }}%"></div></div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="plans-heading" class="space-y-3">
        <h2 id="plans-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Plans</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($plans as $option)
                @php($isCurrent = $option->key === ($subscription?->plan ?? $plan->key))
                @php($over = $option->priceCents < $plan->priceCents ? $overLimits($option) : [])
                <div @class(['flex flex-col justify-between gap-3 rounded-lg border bg-white p-4 shadow-sm', 'border-indigo-400 ring-1 ring-indigo-400' => $isCurrent, 'border-gray-200' => ! $isCurrent]) data-plan="{{ $option->key }}">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $option->name }}@if ($isCurrent) <span class="text-xs font-medium text-indigo-700">· Current</span>@endif</p>
                        <p class="text-sm text-gray-600">{{ $option->priceLabel() }}</p>
                        <ul class="mt-2 space-y-1 text-sm text-gray-700">
                            @foreach (\App\Enums\Billing\LimitKey::cases() as $limitKey)
                                <li>{{ $option->limit($limitKey) === null ? 'Unlimited' : number_format($option->limit($limitKey)) }} {{ strtolower($limitKey->label()) }}</li>
                            @endforeach
                        </ul>
                        @if ($over)
                            <p class="mt-2 text-xs text-amber-800">You're over this plan's limits ({{ implode('; ', $over) }}). Nothing is deleted, but you won't be able to add more.</p>
                        @endif
                    </div>
                    <div>
                        @if ($isCurrent)
                            @if ($subscription?->scheduled_plan)
                                <button type="button" wire:click="choosePlan('{{ $option->key }}')" class="{{ $secondary }} w-full">Keep {{ $option->name }}</button>
                            @endif
                        @elseif ($option->isFree())
                            @if ($subscription && ! $subscription->cancel_at_period_end)
                                <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:confirm="Move to Free at the end of the current period? Nothing is deleted." class="{{ $secondary }} w-full">Downgrade to Free</button>
                            @endif
                        @elseif (! $subscription)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" class="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">{{ $trialDays > 0 ? "Start {$trialDays}-day free trial" : "Choose {$option->name}" }}</button>
                        @elseif ($subscription->scheduled_plan !== $option->key)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" @if ($option->priceCents < $plan->priceCents) wire:confirm="Switch to {{ $option->name }} at the end of the current period? Nothing is deleted." @endif
                                    @class(['w-full rounded-md px-3 py-1.5 text-sm font-semibold disabled:opacity-50', 'bg-indigo-600 text-white hover:bg-indigo-500' => $option->priceCents > $plan->priceCents, 'bg-white text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50' => $option->priceCents <= $plan->priceCents])>
                                {{ $option->priceCents > $plan->priceCents ? "Upgrade to {$option->name}" : "Downgrade to {$option->name}" }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-settings.shell>

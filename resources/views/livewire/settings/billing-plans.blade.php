@php($secondary = 'w-full rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 disabled:opacity-50')
@php($primary = 'w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50')
<x-settings.shell title="Plans" description="Compare plans and change yours. Prices are per month in USD; limits are what each plan includes.">
    @include('livewire.settings.partials.billing-tabs')
    @include('livewire.settings.partials.status')

    @if ($subscription?->cancel_at_period_end)
        <p class="rounded-md bg-gray-50 p-3 text-sm text-gray-800" data-cancellation>Your subscription is set to end on {{ $organization->formatDate($subscription->current_period_end) }}. Resume it from the <a href="{{ route('settings.billing') }}" wire:navigate class="font-medium underline">Plan page</a> to keep your plan.</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($plans as $option)
            @php($isCurrent = $option->key === ($subscription?->plan ?? $plan->key))
            @php($over = $option->priceCents < $plan->priceCents ? $overLimits($option) : [])
            <div @class(['flex flex-col justify-between gap-4 rounded-lg border bg-white p-4 shadow-sm sm:p-5', 'border-indigo-400 ring-1 ring-indigo-400' => $isCurrent, 'border-gray-200' => ! $isCurrent]) data-plan="{{ $option->key }}">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">{{ $option->name }}</h2>
                        @if ($isCurrent)<span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Current plan</span>@endif
                    </div>
                    <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $option->isFree() ? 'Free' : \App\Support\Money::format($option->priceCents) }}@unless ($option->isFree()) <span class="text-sm font-normal text-gray-600">/ {{ $option->interval->value }}</span>@endunless</p>

                    <ul class="mt-4 space-y-1.5 text-sm text-gray-700" aria-label="{{ $option->name }} limits">
                        @foreach (\App\Enums\Billing\LimitKey::cases() as $limitKey)
                            <li>{{ $option->limit($limitKey) === null ? 'Unlimited' : number_format($option->limit($limitKey)) }} {{ strtolower($limitKey->label()) }}</li>
                        @endforeach
                    </ul>
                    <ul class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 text-sm text-gray-700" aria-label="{{ $option->name }} features">
                        @foreach ($option->features as $feature)
                            <li>{{ $featureLabels[$feature] ?? ucfirst(str_replace('_', ' ', $feature)) }}</li>
                        @endforeach
                    </ul>

                    @if ($over)
                        <p class="mt-3 text-xs text-amber-800" data-over-limits>You're over this plan's limits ({{ implode('; ', $over) }}). Nothing is deleted, but you won't be able to add more.</p>
                    @endif
                </div>

                <div class="space-y-2">
                    @if ($isCurrent)
                        @if ($subscription?->scheduled_plan)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" class="{{ $secondary }}">Keep {{ $option->name }}</button>
                            <p class="text-xs text-gray-500">Cancels your scheduled change to {{ $subscription->scheduledPlanDefinition()?->name }}.</p>
                        @endif
                    @elseif ($option->isFree())
                        @if ($subscription && ! $subscription->cancel_at_period_end)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:confirm="Move to Free at the end of the current period? Nothing is deleted." class="{{ $secondary }}">Downgrade to Free</button>
                            <p class="text-xs text-gray-500">Takes effect at the end of your billing period.</p>
                        @endif
                    @elseif (! $subscription)
                        <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" class="{{ $primary }}">{{ $trialDays > 0 ? "Start {$trialDays}-day free trial" : "Choose {$option->name}" }}</button>
                        <p class="text-xs text-gray-500">You'll finish on our payment provider's secure page.</p>
                    @elseif ($subscription->scheduled_plan !== $option->key)
                        @if ($option->priceCents > $plan->priceCents)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" class="{{ $primary }}">Upgrade to {{ $option->name }}</button>
                            <p class="text-xs text-gray-500">Starts now; you pay the prorated difference.</p>
                        @else
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" wire:confirm="Switch to {{ $option->name }} at the end of the current period? Nothing is deleted." class="{{ $secondary }}">Downgrade to {{ $option->name }}</button>
                            <p class="text-xs text-gray-500">Takes effect at the end of your billing period.</p>
                        @endif
                    @else
                        <p class="text-xs text-gray-600" data-scheduled>Scheduled for {{ $organization->formatDate($subscription->scheduled_change_at) }}.</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-settings.shell>

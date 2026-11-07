@php($secondary = 'w-full rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50 disabled:opacity-50')
@php($primary = 'w-full rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50')
@php($popular = config('billing.popular_plan', 'pro'))
<x-settings.shell title="Plans" description="Compare plans and change yours. Prices are per month in USD; limits are what each plan includes.">
    @include('livewire.settings.partials.billing-tabs')
    @include('livewire.settings.partials.status')

    @if ($subscription?->cancel_at_period_end)
        <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-800" data-cancellation>Your subscription is set to end on {{ $organization->formatDate($subscription->current_period_end) }}. Resume it from the <a href="{{ route('settings.billing') }}" wire:navigate class="font-medium underline">Plan page</a> to keep your plan.</p>
    @endif

    <div class="grid gap-5 pt-3 lg:grid-cols-3">
        @foreach ($plans as $option)
            @php($isCurrent = $option->key === ($subscription?->plan ?? $plan->key))
            @php($over = $option->priceCents < $plan->priceCents ? $overLimits($option) : [])
            @php($isPopular = $option->key === $popular)
            <div @class(['relative flex flex-col justify-between gap-5 rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] sm:p-6', 'ring-2 ring-violet-500' => $isPopular || $isCurrent, 'ring-1 ring-slate-200/70' => ! $isPopular && ! $isCurrent]) data-plan="{{ $option->key }}">
                @if ($isPopular)
                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-linear-to-r from-violet-600 to-violet-500 px-3 py-1 text-xs font-semibold whitespace-nowrap text-white shadow-[0_6px_14px_-6px_rgba(109,40,217,0.7)]">Most Popular</span>
                @endif
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <span @class(['grid size-10 place-items-center rounded-xl ring-1 ring-inset', 'bg-violet-50 text-violet-600 ring-violet-100' => $isPopular, 'bg-slate-50 text-slate-500 ring-slate-200' => ! $isPopular])><x-ui-icon :name="$option->isFree() ? 'cube' : ($isPopular ? 'crown' : 'bolt')" class="size-5" /></span>
                            <h2 class="text-lg font-bold text-slate-900">{{ $option->name }}</h2>
                        </div>
                        @if ($isCurrent)<span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 ring-inset">Current plan</span>@endif
                    </div>
                    <p class="mt-4 text-3xl font-bold tracking-tight text-slate-900">{{ $option->isFree() ? '$0' : \App\Support\Money::format($option->priceCents) }}@unless ($option->isFree()) <span class="text-sm font-medium text-slate-500">/ {{ $option->interval->value }}</span>@endunless</p>

                    <ul class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-sm text-slate-700" aria-label="{{ $option->name }} limits">
                        @foreach (\App\Enums\Billing\LimitKey::cases() as $limitKey)
                            @php($limit = $option->limit($limitKey))
                            <li class="flex items-start gap-2.5 {{ $limit === 0 ? 'text-slate-400' : '' }}"><x-ui-icon :name="$limit === 0 ? 'close' : 'check'" @class(['mt-0.5 size-4 shrink-0 stroke-2', 'text-emerald-600' => $limit !== 0, 'text-slate-300' => $limit === 0]) />@php($name = preg_match('/^[A-Z]{2}/', $limitKey->label()) ? $limitKey->label() : lcfirst($limitKey->label()))<span>{{ $limit === 0 ? 'No '.$name : ($limit === null ? 'Unlimited' : number_format($limit)).' '.$name }}</span></li>
                        @endforeach
                    </ul>
                    @if ($option->features !== [])
                        <ul class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-sm text-slate-700" aria-label="{{ $option->name }} features">
                            @foreach ($option->features as $feature)
                                <li class="flex items-start gap-2.5"><x-ui-icon name="sparkles" class="mt-0.5 size-4 shrink-0 text-violet-500" /><span>{{ $featureLabels[$feature] ?? ucfirst(str_replace('_', ' ', $feature)) }}</span></li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($over)
                        <p class="mt-3 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800" data-over-limits>You're over this plan's limits ({{ implode('; ', $over) }}). Nothing is deleted, but you won't be able to add more.</p>
                    @endif
                </div>

                <div class="space-y-2">
                    @if ($isCurrent)
                        @if (! $subscription?->scheduled_plan)
                            <button type="button" disabled class="{{ $secondary }}">Current plan</button>
                        @endif
                        @if ($subscription?->scheduled_plan)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" class="{{ $secondary }}">Keep {{ $option->name }}</button>
                            <p class="text-xs text-slate-500">Cancels your scheduled change to {{ $subscription->scheduledPlanDefinition()?->name }}.</p>
                        @endif
                    @elseif ($option->isFree())
                        @if ($subscription && ! $subscription->cancel_at_period_end)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:confirm="Move to Free at the end of the current period? Nothing is deleted." class="{{ $secondary }}">Downgrade to Free</button>
                            <p class="text-xs text-slate-500">Takes effect at the end of your billing period.</p>
                        @endif
                    @elseif (! $subscription)
                        <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" class="{{ $primary }}">{{ $trialDays > 0 ? "Start {$trialDays}-day free trial" : "Choose {$option->name}" }}</button>
                        <p class="text-xs text-slate-500">You'll finish on our payment provider's secure page.</p>
                    @elseif ($subscription->scheduled_plan !== $option->key)
                        @if ($option->priceCents > $plan->priceCents)
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" class="{{ $primary }}">Upgrade to {{ $option->name }}</button>
                            <p class="text-xs text-slate-500">Starts now; you pay the prorated difference.</p>
                        @else
                            <button type="button" wire:click="choosePlan('{{ $option->key }}')" wire:loading.attr="disabled" wire:confirm="Switch to {{ $option->name }} at the end of the current period? Nothing is deleted." class="{{ $secondary }}">Downgrade to {{ $option->name }}</button>
                            <p class="text-xs text-slate-500">Takes effect at the end of your billing period.</p>
                        @endif
                    @else
                        <p class="text-xs text-slate-500" data-scheduled>Scheduled for {{ $organization->formatDate($subscription->scheduled_change_at) }}.</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-settings.shell>

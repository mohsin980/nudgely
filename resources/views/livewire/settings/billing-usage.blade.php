@php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
@php($icons = ['customers' => 'users', 'automations' => 'bolt', 'team_members' => 'user-plus', 'outbound_emails' => 'envelope', 'estimates' => 'document', 'ai_conversations' => 'sparkles'])
<x-settings.shell title="Usage" :description="'How much of your '.$plan->name.' plan you are using.'">
    @include('livewire.settings.partials.billing-tabs')
    @include('livewire.settings.partials.status')

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <section aria-labelledby="usage-heading" class="{{ $card }}">
            <h2 id="usage-heading" class="text-lg font-bold text-slate-900">This billing period</h2>
            <p class="mt-1 text-xs text-slate-500" data-period>Emails, estimates and AI conversations: {{ $organization->formatDate($periodStart) }} – {{ $organization->formatDate($periodEnd) }}. Other limits count what you have now.</p>

            <ul role="list" class="mt-3 divide-y divide-slate-100" data-section="usage">
                @foreach ($rows as $key => $row)
                    @php($limitKey = \App\Enums\Billing\LimitKey::from($key))
                    @php($percent = $row['limit'] ? min(100, (int) round($row['used'] / max(1, $row['limit']) * 100)) : 0)
                    @php($notIncluded = $row['limit'] === 0 && $row['used'] === 0)
                    @php($reached = ! $notIncluded && $row['limit'] !== null && $row['used'] >= $row['limit'])
                    <li class="flex items-start gap-3.5 py-3.5" data-limit="{{ $key }}">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 ring-inset"><x-ui-icon :name="$icons[$key] ?? 'chart-bar'" class="size-4" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="font-semibold text-slate-800">{{ $row['label'] }}</span>
                                <span @class(['whitespace-nowrap font-semibold', 'text-red-700' => $reached, 'text-slate-400' => $notIncluded, 'text-slate-900' => ! $reached && ! $notIncluded]) data-count>{{ $notIncluded ? 'Not in your plan' : number_format($row['used']).' / '.($row['limit'] === null ? 'Unlimited' : number_format($row['limit'])) }}</span>
                            </div>
                            @if ($row['limit'])
                                <div class="mt-2 h-2 rounded-full bg-slate-100" role="progressbar" aria-label="{{ $row['label'] }} used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                    <div @class(['h-2 rounded-full', 'bg-red-500' => $percent >= 100, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-linear-to-r from-violet-600 to-violet-400' => $percent < 80]) style="width: {{ max($percent, $row['used'] > 0 ? 2 : 0) }}%"></div>
                                </div>
                            @endif
                            @if ($reached)
                                <p class="mt-1.5 text-sm text-slate-700" data-reached>You've reached your {{ $limitKey->shortName() }} limit. <a href="{{ route('settings.billing.plans') }}" wire:navigate class="font-semibold text-violet-700 underline">Upgrade Plan</a></p>
                                @if ($row['over'])
                                    <p class="text-xs text-slate-500">You're over the limit, so existing records stay but new ones can't be added.</p>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="billing-side-heading" class="{{ $card }} h-fit space-y-4" data-section="billing-summary">
            <h2 id="billing-side-heading" class="text-lg font-bold text-slate-900">Billing</h2>
            <div class="rounded-xl bg-linear-to-br from-violet-50 to-white p-4 ring-1 ring-violet-100 ring-inset">
                <p class="text-xs font-semibold tracking-wide text-violet-700 uppercase">Current plan</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $plan->name }}</p>
                <p class="text-sm text-slate-500">{{ $plan->priceLabel() }}</p>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="flex items-center gap-2 text-slate-500"><x-ui-icon name="calendar" class="size-4" />Next invoice</dt>
                    <dd class="font-semibold text-slate-900" data-next-billing>{{ $nextBilling ? $organization->formatDate($nextBilling['date']) : 'None' }}</dd>
                </div>
                @if ($organization->billing_customer_id)
                    <div class="flex items-center justify-between gap-3">
                        <dt class="flex items-center gap-2 text-slate-500"><x-ui-icon name="lock" class="size-4" />Payment method</dt>
                        <dd class="text-right font-semibold text-slate-900">{{ $payment['card']?->label() ?? ($payment['error'] ? 'Unavailable' : 'None') }}</dd>
                    </div>
                @endif
            </dl>
            <div class="flex flex-col gap-2">
                @if ($organization->billing_customer_id)
                    <button type="button" wire:click="openPortal" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50">Manage Billing</button>
                @endif
                <a href="{{ route('settings.billing.plans') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50">Compare plans<x-ui-icon name="arrow-right" class="size-4" /></a>
            </div>
        </section>
    </div>
</x-settings.shell>

<x-settings.shell title="Usage" :description="'How much of your '.$plan->name.' plan you are using.'">
    @include('livewire.settings.partials.billing-tabs')

    <section aria-labelledby="usage-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <h2 id="usage-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">This billing period</h2>
        <p class="mt-1 text-xs text-gray-500" data-period>Emails and estimates: {{ $organization->formatDate($periodStart) }} – {{ $organization->formatDate($periodEnd) }}. Other limits count what you have now.</p>

        <ul role="list" class="mt-4 space-y-5" data-section="usage">
            @foreach ($rows as $key => $row)
                @php($limitKey = \App\Enums\Billing\LimitKey::from($key))
                @php($percent = $row['limit'] ? min(100, (int) round($row['used'] / max(1, $row['limit']) * 100)) : 0)
                @php($reached = $row['limit'] !== null && $row['used'] >= $row['limit'])
                <li data-limit="{{ $key }}">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-gray-800">{{ $row['label'] }}</span>
                        <span @class(['font-medium whitespace-nowrap', 'text-red-700' => $reached, 'text-gray-900' => ! $reached]) data-count>{{ number_format($row['used']) }} / {{ $row['limit'] === null ? 'Unlimited' : number_format($row['limit']) }}</span>
                    </div>
                    @if ($row['limit'])
                        <div class="mt-1.5 h-2 rounded-full bg-gray-100" role="progressbar" aria-label="{{ $row['label'] }} used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                            <div @class(['h-2 rounded-full', 'bg-red-500' => $percent >= 100, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-indigo-500' => $percent < 80]) style="width: {{ $percent }}%"></div>
                        </div>
                    @endif
                    @if ($reached)
                        <p class="mt-1.5 text-sm text-gray-700" data-reached>You've reached your {{ $limitKey->shortName() }} limit. <a href="{{ route('settings.billing.plans') }}" wire:navigate class="font-semibold text-indigo-700 underline">Upgrade Plan</a></p>
                        @if ($row['over'])
                            <p class="text-xs text-gray-500">You're over the limit, so existing records stay but new ones can't be added.</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</x-settings.shell>

@php($card = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm')
<x-settings.shell title="Billing" description="Your plan and how much of it you're using.">
    <section aria-labelledby="plan-heading" class="{{ $card }}" data-section="current-plan">
        <h2 id="plan-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Current plan</h2>
        <p class="mt-2 text-xl font-semibold text-gray-900">{{ $plan->name }} <span class="text-sm font-normal text-gray-600">· {{ $plan->priceLabel() }}</span></p>
        @if ($subscription)
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Status</dt><dd class="font-medium text-gray-900" data-status>{{ $subscription->status->label() }}@if ($subscription->cancel_at_period_end) · cancels at period end @endif</dd></div>
                @if ($subscription->trial_ends_at)<div><dt class="text-gray-500">Trial ends</dt><dd class="text-gray-900">{{ $organization->formatDate($subscription->trial_ends_at) }}</dd></div>@endif
                @if ($subscription->current_period_end)<div><dt class="text-gray-500">Current period ends</dt><dd class="text-gray-900">{{ $organization->formatDate($subscription->current_period_end) }}</dd></div>@endif
            </dl>
            @unless ($subscription->grantsAccess())
                <p class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900">This subscription doesn't currently give access to its plan, so the {{ $plan->name }} plan limits apply.</p>
            @endunless
        @else
            <p class="mt-2 text-sm text-gray-600">No subscription — you're on the {{ $plan->name }} plan.</p>
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
                <div @class(['rounded-lg border bg-white p-4 shadow-sm', 'border-indigo-400 ring-1 ring-indigo-400' => $option->key === $plan->key, 'border-gray-200' => $option->key !== $plan->key]) data-plan="{{ $option->key }}">
                    <p class="font-semibold text-gray-900">{{ $option->name }}@if ($option->key === $plan->key) <span class="text-xs font-medium text-indigo-700">· Current</span>@endif</p>
                    <p class="text-sm text-gray-600">{{ $option->priceLabel() }}</p>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700">
                        @foreach (\App\Enums\Billing\LimitKey::cases() as $limitKey)
                            <li>{{ $option->limit($limitKey) === null ? 'Unlimited' : number_format($option->limit($limitKey)) }} {{ strtolower($limitKey->label()) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <p class="text-sm text-gray-600">Online payment and plan changes are coming soon.</p>
    </section>
</x-settings.shell>

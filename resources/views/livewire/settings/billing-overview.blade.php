@php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
@php($secondary = 'inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 disabled:opacity-50')
@php($primary = 'inline-flex justify-center rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500 disabled:opacity-50')
<x-settings.shell title="Billing" description="Your plan, what happens next, and your payment details. Cards are handled securely by our payment provider.">
    @include('livewire.settings.partials.billing-tabs')
    @include('livewire.settings.partials.status')

    <section aria-labelledby="plan-heading" class="{{ $card }}" data-section="current-plan">
        <h2 id="plan-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Current plan</h2>
        <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p class="text-2xl font-semibold text-gray-900" data-plan-name>{{ $plan->name }}</p>
            <p class="text-sm text-gray-600" data-plan-price>{{ $plan->priceLabel() }}</p>
            @if ($subscription)
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800" data-status>{{ $subscription->status->label() }}</span>
            @endif
        </div>

        @if ($subscription)
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                @if ($subscription->current_period_start && $subscription->current_period_end)
                    <div><dt class="text-gray-500">Billing period</dt><dd class="text-gray-900" data-billing-period>{{ $organization->formatDate($subscription->current_period_start) }} – {{ $organization->formatDate($subscription->current_period_end) }}</dd></div>
                @endif
                @if ($subscription->trial_ends_at && $subscription->onTrial())
                    <div><dt class="text-gray-500">Trial ends</dt><dd class="text-gray-900">{{ $organization->formatDate($subscription->trial_ends_at) }}</dd></div>
                @endif
                <div>
                    <dt class="text-gray-500">Next billing date</dt>
                    <dd class="text-gray-900" data-next-billing>
                        @if ($nextBilling)
                            {{ $organization->formatDate($nextBilling['date']) }} <span class="text-gray-600">({{ $nextBilling['plan'] }})</span>
                        @else
                            None
                        @endif
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-gray-800" data-notice>{{ $notice }}</p>

            @unless ($subscription->grantsAccess())
                <p class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900">This subscription doesn't currently give access to its plan, so the {{ $plan->name }} plan limits apply. Update your payment method to continue.</p>
            @endunless
            @if ($subscription->status === \App\Enums\Billing\SubscriptionStatus::PastDue)
                <p class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900">Your last payment didn't go through. Update your payment method to keep your plan.</p>
            @endif

            @if ($subscription->cancel_at_period_end)
                <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm text-gray-800" data-cancellation>
                    <p>Cancellation is scheduled. Your subscription remains active until <strong>{{ $organization->formatDate($subscription->current_period_end) }}</strong>, then you move to the Free plan. Nothing is deleted.</p>
                </div>
            @endif
        @elseif ($signupTrial)
            <p class="mt-3 text-sm text-gray-800" data-notice>Your free {{ $signupTrial['plan']->name }} trial ends on {{ $organization->formatDate($signupTrial['ends_at']) }} ({{ $signupTrial['days_left'] }} {{ \Illuminate\Support\Str::plural('day', $signupTrial['days_left']) }} left). No card needed. Choose a plan to keep your limits; otherwise you move to Free and nothing is deleted.</p>
        @else
            <p class="mt-3 text-sm text-gray-600" data-notice>No subscription — you're on the {{ $plan->name }} plan.@if ($trialDays > 0) Paid plans start with a {{ $trialDays }}-day free trial.@endif</p>
        @endif

        <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <a href="{{ route('settings.billing.plans') }}" wire:navigate class="{{ $primary }}">Change plan</a>
            @if ($organization->billing_customer_id)
                <button type="button" wire:click="openPortal" wire:loading.attr="disabled" class="{{ $secondary }}">Manage billing</button>
            @endif
            @if ($subscription?->cancel_at_period_end)
                <button type="button" wire:click="resumeSubscription" wire:loading.attr="disabled" class="{{ $secondary }}" data-action="resume">Resume subscription</button>
            @elseif ($subscription && ! $subscription->status->isTerminal())
                <button type="button" wire:click="cancelSubscription" wire:confirm="Cancel your subscription? It stays active until the end of the current period, then you move to the Free plan. Nothing is deleted." class="rounded-md px-3 py-2 text-sm font-medium text-red-700 hover:underline" data-action="cancel">Cancel subscription</button>
            @endif
        </div>
    </section>

    @if ($atLimit !== [])
        <section class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-section="limit-notice">
            @foreach ($atLimit as $name)
                <p>You've reached your {{ $name }} limit.</p>
            @endforeach
            <a href="{{ route('settings.billing.plans') }}" wire:navigate class="mt-2 inline-block font-semibold underline">Upgrade Plan</a>
        </section>
    @endif

    @if ($organization->billing_customer_id)
        <section aria-labelledby="payment-heading" class="{{ $card }}" data-section="payment">
            <h2 id="payment-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Payment method</h2>
            @if ($payment['error'])
                <p class="mt-2 text-sm text-gray-600">{{ $payment['error'] }}</p>
            @elseif ($payment['card'])
                <p class="mt-2 text-sm text-gray-900" data-card>{{ $payment['card']->label() }}@if ($payment['card']->expiry()) <span class="text-gray-600">· expires {{ $payment['card']->expiry() }}</span>@endif</p>
            @else
                <p class="mt-2 text-sm text-gray-600">No payment method on file.</p>
            @endif
            <p class="mt-1 text-xs text-gray-500">Only the card type and last four digits are shown. Change your card with “Manage billing”.</p>
        </section>
    @endif
</x-settings.shell>

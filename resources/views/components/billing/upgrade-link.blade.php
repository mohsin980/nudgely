{{-- A calm "Upgrade Plan" link after a plan-limit message. Only people allowed to change the plan see it. --}}
@can('manage-billing')
    <a href="{{ route('settings.billing.plans') }}" wire:navigate {{ $attributes->merge(['class' => 'font-semibold text-indigo-700 underline']) }}>Upgrade Plan</a>
@endcan

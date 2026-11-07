{{-- "We can set it up with you": shown under setup guides. Uses SUPPORT_EMAIL when it is configured. --}}
@php($supportEmail = config('app.support_email'))
<div class="mt-5 flex items-start gap-3 rounded-xl bg-violet-50 p-4 text-sm text-violet-900 ring-1 ring-violet-100 ring-inset" data-setup-help>
    <x-ui-icon name="info" class="mt-0.5 size-5 shrink-0 text-violet-600" />
    <p>
        <span class="font-semibold">Need a hand?</span> Our support team can help you set up {{ $topic }} for your account, or do it with you on a short call.
        @if ($supportEmail)
            <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode($topic.' setup help') }}" class="font-semibold text-violet-700 underline">Contact support</a> ({{ $supportEmail }}).
        @else
            Contact QuoteFlow support to get started.
        @endif
    </p>
</div>

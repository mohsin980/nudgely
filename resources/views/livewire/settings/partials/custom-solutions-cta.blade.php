{{-- "Need something custom?": for businesses whose needs no plan covers. Uses SUPPORT_EMAIL when it is configured. --}}
@php($supportEmail = config('app.support_email'))
<div class="mt-8 flex flex-col items-start justify-between gap-4 rounded-2xl bg-slate-50 p-5 ring-1 ring-slate-200 ring-inset sm:flex-row sm:items-center" data-custom-solutions>
    <div>
        <p class="font-semibold text-slate-900">Need something custom?</p>
        <p class="mt-1 text-sm text-slate-600">If no plan fits, such as call coverage only, a custom package, or a larger volume, talk to our support team.</p>
    </div>
    @if ($supportEmail)
        <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('Custom solution enquiry') }}" class="inline-flex shrink-0 items-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-500" data-talk-to-support>Talk to support</a>
    @else
        <p class="shrink-0 text-sm font-semibold text-slate-700" data-talk-to-support>Contact QuoteFlow support to discuss a custom solution.</p>
    @endif
</div>

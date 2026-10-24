<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Estimate {{ $estimate->displayNumber() }} · {{ $document->organization->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full font-sans text-slate-900 antialiased">
    @php($status = $estimate->status)
    <main class="mx-auto max-w-3xl space-y-4 px-4 py-6 sm:py-10">
        <div class="flex items-center gap-3">
            @if ($document->organization->logoUrl())
                <img src="{{ $document->organization->logoUrl() }}" alt="{{ $document->organization->name }} logo" class="h-10 max-w-40 object-contain">
            @endif
            <p class="text-sm text-slate-500">Estimate from <span class="font-semibold text-slate-900">{{ $document->organization->name }}</span></p>
        </div>

        @if (session('estimate-status'))
            <div role="status" class="rounded-lg bg-green-50 p-4 text-sm text-green-800">{{ session('estimate-status') }}</div>
        @endif
        @if (session('estimate-error'))
            <div role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ session('estimate-error') }}</div>
        @endif

        @switch($status)
            @case(\App\Enums\EstimateStatus::Accepted)
                <div class="rounded-lg bg-green-50 p-4 text-sm text-green-800" data-state="accepted"><p class="font-semibold">Accepted</p><p>You accepted this estimate on {{ $document->organization->localTime($estimate->accepted_at)->format('F j, Y') }}.</p></div>
                @break
            @case(\App\Enums\EstimateStatus::Declined)
                <div class="rounded-lg bg-slate-100 p-4 text-sm text-slate-700" data-state="declined"><p class="font-semibold">Declined</p><p>You declined this estimate on {{ $document->organization->localTime($estimate->declined_at)->format('F j, Y') }}.</p></div>
                @break
            @case(\App\Enums\EstimateStatus::Expired)
                <div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900" data-state="expired"><p class="font-semibold">This estimate has expired.</p><p>Please contact us for an updated estimate.</p></div>
                @break
            @case(\App\Enums\EstimateStatus::Cancelled)
                <div class="rounded-lg bg-slate-100 p-4 text-sm text-slate-700" data-state="cancelled"><p class="font-semibold">This estimate is no longer available.</p><p>It may have been replaced by an updated estimate. Please check your email or contact us.</p></div>
                @break
        @endswitch

        <article class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-8" aria-label="Estimate {{ $estimate->displayNumber() }}">
            @include('estimates.document', ['document' => $document])
        </article>

        @if ($status->isAwaitingCustomer())
            <section aria-label="Your decision" class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <form method="POST" action="{{ route('estimates.public.accept', $token) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 sm:w-auto">Accept Estimate</button>
                    </form>
                    @if ($askUrl)
                        {{-- A mailto link does nothing when no mail app is set up, so the address is shown as well. --}}
                        <details class="group w-full sm:w-auto">
                            <summary class="block w-full cursor-pointer list-none rounded-lg bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 sm:w-auto">Ask a Question</summary>
                            <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700 sm:max-w-sm">
                                <p>Email your question to <a href="{{ $askUrl }}" class="font-semibold text-violet-700 underline break-all">{{ $askEmail }}</a>. Your reply is added to this estimate.</p>
                            </div>
                        </details>
                    @endif
                </div>
                <details class="text-sm">
                    <summary class="cursor-pointer font-medium text-slate-700 hover:underline">Decline Estimate</summary>
                    <form method="POST" action="{{ route('estimates.public.decline', $token) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label for="decline-reason" class="block font-medium text-slate-700">Reason <span class="font-normal text-slate-500">(optional)</span></label>
                            <select id="decline-reason" name="reason" class="mt-1 block w-full rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-slate-300 ring-inset sm:max-w-xs">
                                <option value="">Choose a reason…</option>
                                @foreach ($declineReasons as $reason)
                                    <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="decline-note" class="block font-medium text-slate-700">Anything else? <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="decline-note" name="note" rows="2" maxlength="500" class="mt-1 block w-full rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-slate-300 ring-inset"></textarea>
                        </div>
                        <button type="submit" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-red-700 ring-1 ring-red-300 ring-inset hover:bg-red-50">Decline Estimate</button>
                    </form>
                </details>
                <p class="text-xs text-slate-500">Accepting doesn't charge you anything. We will contact you to schedule the work.</p>
            </section>
        @endif
    </main>
</body>
</html>

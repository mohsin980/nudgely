<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Unsubscribe · {{ $business }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full font-sans text-slate-900 antialiased">
    <main class="mx-auto max-w-md space-y-4 px-4 py-10">
        <div class="rounded-2xl bg-white p-6 shadow-xs ring-1 ring-slate-200">
            @if ($done)
                <h1 class="text-lg font-semibold" data-state="unsubscribed">You're unsubscribed</h1>
                <p class="mt-2 text-sm text-slate-600">{{ $address }} will no longer get emails from {{ $business }}. If this was a mistake, contact {{ $business }} directly.</p>
            @else
                <h1 class="text-lg font-semibold" data-state="confirm">Stop emails from {{ $business }}?</h1>
                <p class="mt-2 text-sm text-slate-600">You'll stop getting estimates and follow-ups at {{ $address }}.</p>
                <form method="POST" action="{{ request()->fullUrl() }}" class="mt-5">
                    <button type="submit" class="w-full rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700">Unsubscribe</button>
                </form>
            @endif
        </div>
    </main>
</body>
</html>

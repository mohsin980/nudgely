<div class="space-y-6" aria-busy="true" aria-live="polite">
    <span class="sr-only">Loading your dashboard…</span>
    <div class="h-8 w-64 animate-pulse rounded bg-gray-200"></div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach (range(1, 4) as $i)
            <div class="h-24 animate-pulse rounded-lg bg-gray-200"></div>
        @endforeach
    </div>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="h-72 animate-pulse rounded-lg bg-gray-200 lg:col-span-2"></div>
        <div class="h-72 animate-pulse rounded-lg bg-gray-200"></div>
    </div>
</div>

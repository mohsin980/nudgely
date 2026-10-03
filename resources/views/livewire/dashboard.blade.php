<div class="space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Dashboard</h1>

    <section aria-labelledby="follow-ups-widget" class="max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <h2 id="follow-ups-widget" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Follow-ups</h2>
        <dl class="mt-3 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-md bg-red-50 p-3">
                <dt class="text-xs font-medium text-red-700">Overdue</dt>
                <dd class="text-2xl font-semibold text-red-800" data-count="overdue">{{ $followUps['overdue'] }}</dd>
            </div>
            <div class="rounded-md bg-amber-50 p-3">
                <dt class="text-xs font-medium text-amber-800">Due today</dt>
                <dd class="text-2xl font-semibold text-amber-900" data-count="due_today">{{ $followUps['due_today'] }}</dd>
            </div>
            <div class="rounded-md bg-gray-50 p-3">
                <dt class="text-xs font-medium text-gray-600">Upcoming</dt>
                <dd class="text-2xl font-semibold text-gray-900" data-count="upcoming">{{ $followUps['upcoming'] }}</dd>
            </div>
        </dl>
        <a href="{{ route('follow-ups.index') }}" wire:navigate class="mt-4 inline-flex rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">View Follow-Ups</a>
    </section>
</div>

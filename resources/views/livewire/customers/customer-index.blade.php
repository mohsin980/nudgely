<div class="space-y-6">
    @php($field = 'block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Customers</h1>
            <p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count customer|:count customers', $customers->total()) }}{{ $filtering ? ' match your filters' : '' }}</p>
        </div>
        <a href="{{ route('customers.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Add Customer</a>
    </div>

    @if (! $hasAnyCustomer)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-base font-semibold text-slate-900">No customers yet.</p>
            <p class="mt-1 text-sm text-slate-500">Add your first customer to start a conversation.</p>
            <a href="{{ route('customers.create') }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700">Add Customer</a>
        </div>
    @else
        <section aria-label="Search and filters" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs sm:p-5">
            <div>
                <label for="customer-search" class="sr-only">Search customers</label>
                <input id="customer-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name, email or phone" class="{{ $field }}">
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <div><label for="f-status" class="block text-xs font-medium text-slate-500">Status</label>
                    <select id="f-status" wire:model.live="status" class="mt-1 {{ $field }}"><option value="">All</option>@foreach ($customerStatuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
                <div><label for="f-conversation" class="block text-xs font-medium text-slate-500">Conversation</label>
                    <select id="f-conversation" wire:model.live="conversation" class="mt-1 {{ $field }}"><option value="">All</option>@foreach ($conversationStatuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
                <div><label for="f-follow-up" class="block text-xs font-medium text-slate-500">Follow-up</label>
                    <select id="f-follow-up" wire:model.live="followUp" class="mt-1 {{ $field }}"><option value="">All</option><option value="overdue">Overdue</option><option value="due_today">Due today</option><option value="upcoming">Upcoming</option><option value="none">None</option></select></div>
                <div><label for="f-intent" class="block text-xs font-medium text-slate-500">AI intent</label>
                    <select id="f-intent" wire:model.live="intent" class="mt-1 {{ $field }}"><option value="">All</option>@foreach ($intents as $i)<option value="{{ $i->value }}">{{ $i->label() }}</option>@endforeach<option value="other">Other</option></select></div>
                <div><label for="f-sort" class="block text-xs font-medium text-slate-500">Sort</label>
                    <select id="f-sort" wire:model.live="sort" class="mt-1 {{ $field }}">@foreach (\App\Services\Customers\CustomerDirectory::SORTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="f-per-page" class="block text-xs font-medium text-slate-500">Per page</label>
                    <select id="f-per-page" wire:model.live="perPage" class="mt-1 {{ $field }}">@foreach (\App\Services\Customers\CustomerDirectory::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></div>
            </div>
            @if ($filtering)
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-violet-700 hover:underline">Clear filters</button>
            @endif
        </section>

        <div wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($customers->isEmpty())
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">No customers match your search or filters.</p>
            @else
                <ul role="list" class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs" data-section="customers">
                    @foreach ($customers as $customer)
                        <li wire:key="customer-{{ $customer->id }}">
                            <a href="{{ route('customers.show', $customer->id) }}" wire:navigate class="grid gap-2 px-4 py-3 hover:bg-slate-50 focus:outline-none focus-visible:bg-slate-50 focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-inset sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-center">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 font-semibold text-slate-900">
                                        <span class="truncate">{{ $customer->name }}</span>@if ($customer->is_demo)<span class="ml-2 shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800" data-sample-badge>Sample</span>@endif
                                        @if (! $customer->isActive())
                                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-500">Inactive</span>
                                        @endif
                                    </p>
                                    <p class="truncate text-sm text-slate-500">{{ $customer->email }}@if ($customer->phone) · {{ $customer->phone }}@endif</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5 text-sm">
                                    @if ($customer->conversation_status)
                                        <x-conversation-status-badge :status="\App\Enums\ConversationStatus::from($customer->conversation_status)" />
                                    @else
                                        <span class="text-slate-500">No conversation</span>
                                    @endif
                                    @if ($customer->latest_intent && ($intentEnum = \App\Enums\CustomerReplyIntent::tryFrom($customer->latest_intent)))
                                        <x-intent-badge :intent="$intentEnum" />
                                    @endif
                                </div>
                                <dl class="grid grid-cols-[auto_1fr] gap-x-2 text-xs text-slate-500 sm:text-right">
                                    <dt class="sm:sr-only">Last activity:</dt>
                                    <dd>{{ $customer->last_activity_at ? 'Active '.$customer->last_activity_at->diffForHumans() : 'Added '.$organization->localTime($customer->created_at)->format('M j, Y') }}</dd>
                                    <dt class="sm:sr-only">Next follow-up:</dt>
                                    <dd>
                                        @if ($customer->next_follow_up_at)
                                            Next follow-up {{ $organization->localTime(\Carbon\CarbonImmutable::parse($customer->next_follow_up_at))->format('M j') }}
                                        @else
                                            No follow-up
                                        @endif
                                    </dd>
                                </dl>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $customers->links() }}</div>
            @endif
        </div>
    @endif
</div>

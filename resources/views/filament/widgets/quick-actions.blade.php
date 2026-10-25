<x-filament-widgets::widget>
    <x-filament::section heading="Quick actions" description="Manage your platform efficiently.">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1" data-quick-actions>
            @forelse ($this->actions() as $action)
                <a href="{{ $action['url'] }}" class="flex items-center gap-3 rounded-xl border border-gray-200 p-3 transition hover:border-violet-300 hover:bg-violet-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-700">
                        <x-filament::icon :icon="$action['icon']" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-900">{{ $action['title'] }}</span>
                        <span class="block truncate text-xs text-gray-500">{{ $action['description'] }}</span>
                    </span>
                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-400" />
                </a>
            @empty
                <p class="text-sm text-gray-500">No actions are available for your role.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

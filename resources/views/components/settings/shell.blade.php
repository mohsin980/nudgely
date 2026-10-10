@props(['title', 'description' => null])
{{-- Settings page frame: section menu (left on desktop, a dropdown on phones), title, description. --}}
@php($menu = \App\Support\Settings\SettingsNavigation::for(auth()->user()))
<div class="space-y-6 lg:grid lg:grid-cols-[12rem_1fr] lg:gap-8 lg:space-y-0">
    <nav aria-label="Settings" class="lg:pt-1">
        <label for="settings-menu" class="sr-only">Settings section</label>
        <select id="settings-menu" class="block w-full rounded-md border-0 px-3 py-2 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 lg:hidden"
                x-data x-on:change="Livewire.navigate($event.target.value)">
            @foreach ($menu as $group)
                <optgroup label="{{ $group['label'] }}">
                    @foreach ($group['items'] as $item)
                        <option value="{{ route($item['route']) }}" @selected(request()->routeIs($item['route'], $item['route'].'.*'))>{{ $item['label'] }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <div class="hidden space-y-5 lg:block">
            @foreach ($menu as $group)
                <div>
                    <p class="px-3 text-xs font-semibold tracking-wide text-gray-500 uppercase">{{ $group['label'] }}</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($group['items'] as $item)
                            @php($active = request()->routeIs($item['route'], $item['route'].'.*'))
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                                   @class(['block rounded-md px-3 py-1.5 text-sm', 'bg-violet-50 font-medium text-violet-700' => $active, 'text-gray-700 hover:bg-gray-100' => ! $active])>{{ $item['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </nav>

    <div class="min-w-0 space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 text-sm text-gray-600">{{ $description }}</p>
            @endif
        </div>
        {{ $slot }}
    </div>
</div>

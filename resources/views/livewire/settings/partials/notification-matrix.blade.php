{{-- On/off switches per notification type and channel. Expects $model ('mine' or 'defaults'), $types, $channels and the matching array. --}}
<div class="overflow-x-auto">
    <table class="min-w-full text-[15px]" data-matrix="{{ $model }}">
        <thead>
            <tr class="text-left text-slate-500">
                <th scope="col" class="py-2 pr-4 text-sm font-medium">Notification</th>
                @foreach ($channels as $channel)<th scope="col" class="px-4 py-2 text-center text-sm font-medium">{{ $channel->label() }}</th>@endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($types as $type)
                <tr>
                    <th scope="row" class="py-3 pr-4 text-left font-normal text-slate-900">{{ $type->label() }}</th>
                    @foreach ($channels as $channel)
                        <td class="px-4 py-3 text-center">
                            <label class="group relative inline-flex cursor-pointer items-center justify-center">
                                <span class="sr-only">{{ $type->label() }} — {{ $channel->label() }}</span>
                                <input type="checkbox" wire:model="{{ $model }}.{{ $type->value }}.{{ $channel->value }}" data-pref="{{ $model }}.{{ $type->value }}.{{ $channel->value }}" class="peer sr-only">
                                <span aria-hidden="true" class="relative inline-flex h-6 w-11 items-center rounded-full bg-slate-300 transition-colors peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500 peer-focus-visible:ring-offset-2 after:absolute after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow-xs after:transition-transform peer-checked:after:translate-x-5"></span>
                            </label>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

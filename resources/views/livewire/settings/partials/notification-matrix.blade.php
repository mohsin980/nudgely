{{-- On/off switches per notification type and channel. Expects $model ('mine' or 'defaults'), $types, $channels and the matching array. --}}
<div class="overflow-x-auto">
    <table class="min-w-full text-sm" data-matrix="{{ $model }}">
        <thead>
            <tr class="text-left text-gray-500">
                <th scope="col" class="py-2 pr-4 font-medium">Notification</th>
                @foreach ($channels as $channel)<th scope="col" class="px-4 py-2 text-center font-medium">{{ $channel->label() }}</th>@endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($types as $type)
                <tr>
                    <th scope="row" class="py-2 pr-4 text-left font-normal text-gray-900">{{ $type->label() }}</th>
                    @foreach ($channels as $channel)
                        <td class="px-4 py-2 text-center">
                            <label class="inline-flex items-center">
                                <span class="sr-only">{{ $type->label() }} — {{ $channel->label() }}</span>
                                <input type="checkbox" wire:model="{{ $model }}.{{ $type->value }}.{{ $channel->value }}" class="h-4 w-4 rounded border-gray-300 text-violet-600" data-pref="{{ $model }}.{{ $type->value }}.{{ $channel->value }}">
                            </label>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

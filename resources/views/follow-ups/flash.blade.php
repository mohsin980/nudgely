@if ($followUpMessage)
    <div wire:key="follow-up-flash-{{ md5($followUpMessage) }}" role="{{ $followUpMessageType === 'error' ? 'alert' : 'status' }}"
         @class(['flex items-start justify-between gap-4 rounded-md p-3 text-sm', 'bg-green-50 text-green-800' => $followUpMessageType === 'success', 'bg-red-50 text-red-800' => $followUpMessageType === 'error'])>
        <p>{{ $followUpMessage }}</p>
        <button type="button" wire:click="$set('followUpMessage', null)" class="shrink-0 font-medium hover:underline">Dismiss</button>
    </div>
@endif

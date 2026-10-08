@if ($statusMessage)
    <div wire:key="status-{{ md5($statusMessage) }}" role="{{ $statusType === 'error' ? 'alert' : 'status' }}"
         @class(['flex items-start justify-between gap-4 rounded-md p-4 text-sm', 'bg-green-50 text-green-800' => $statusType === 'success', 'bg-red-50 text-red-800' => $statusType === 'error'])>
        <p>{{ $statusMessage }}@if ($statusType === 'error' && ($limitReached ?? false)) <x-billing.upgrade-link class="ml-1" />@endif</p>
        <button type="button" wire:click="$set('statusMessage', null)" class="shrink-0 font-medium hover:underline">Dismiss</button>
    </div>
@endif

{{-- Success / error message after a save. Expects $statusMessage and $statusType. --}}
@if ($statusMessage)
    <div wire:key="status-{{ md5($statusMessage) }}" role="{{ $statusType === 'error' ? 'alert' : 'status' }}"
         @class(['rounded-md p-3 text-sm', 'bg-green-50 text-green-800' => $statusType === 'success', 'bg-red-50 text-red-800' => $statusType === 'error', 'bg-blue-50 text-blue-800' => $statusType === 'info'])>
        {{ $statusMessage }}
    </div>
@endif

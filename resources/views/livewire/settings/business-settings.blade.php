<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Business Settings</h1>
        <p class="mt-1 text-sm text-gray-600">Follow-up times are shown and entered in this timezone.</p>
    </div>

    @if ($statusMessage)
        <div role="status" class="rounded-md bg-green-50 p-3 text-sm text-green-800">{{ $statusMessage }}</div>
    @endif

    <form wire:submit="save" class="max-w-md space-y-3 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <label for="timezone" class="block text-sm font-medium text-gray-700">Business timezone</label>
        <select id="timezone" wire:model="timezone" class="block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600">
            @foreach ($timezones as $zone => $label)
                <option value="{{ $zone }}">{{ $label }} ({{ $zone }})</option>
            @endforeach
            @unless (array_key_exists($timezone, $timezones))
                <option value="{{ $timezone }}">{{ $timezone }}</option>
            @endunless
        </select>
        @error('timezone') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save</button>
    </form>
</div>

<x-layouts.guest title="Create your account">
    <h1 class="text-lg font-semibold text-gray-900">Create your business account</h1>
    <p class="mt-1 text-sm text-gray-600">You'll be the owner. Invite your team afterwards.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-4 space-y-4">
        @csrf
        @foreach ([
            'business_name' => ['Business name', 'text', 'organization'],
            'name' => ['Your name', 'text', 'name'],
            'email' => ['Email', 'email', 'username'],
            'password' => ['Password', 'password', 'new-password'],
            'password_confirmation' => ['Confirm password', 'password', 'new-password'],
        ] as $key => [$fieldLabel, $type, $autocomplete])
            <div>
                <label for="{{ $key }}" class="block text-sm font-medium text-gray-700">{{ $fieldLabel }}</label>
                <input id="{{ $key }}" name="{{ $key }}" type="{{ $type }}" @unless ($type === 'password') value="{{ old($key) }}" @endunless required autocomplete="{{ $autocomplete }}"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error($key) <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Create account</button>
    </form>
    <p class="mt-4 text-center text-sm text-gray-600">Already have an account? <a href="{{ route('login') }}" class="font-medium text-indigo-700 hover:underline">Sign in</a></p>
</x-layouts.guest>

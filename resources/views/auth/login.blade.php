<x-layouts.guest title="Sign in">
    <h1 class="text-lg font-semibold text-gray-900">Sign in</h1>

    @if (session('status'))
        <div role="status" class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-4 space-y-4">
        @csrf
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="remember" value="1" class="rounded border-gray-300"> Keep me signed in
        </label>
        <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">Sign in</button>
    </form>
    <p class="mt-4 text-center text-sm text-gray-600">New to QuoteFollow? <a href="{{ route('register') }}" class="font-medium text-indigo-700 hover:underline">Create an account</a></p>
</x-layouts.guest>

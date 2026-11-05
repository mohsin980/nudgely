<x-layouts.sign-in title="Sign in">
    <div class="text-center">
        <h1 class="text-[66px] leading-none font-bold tracking-tight text-slate-900">Welcome back</h1>
        <p class="mt-4 text-[21px] text-slate-500">Sign in to your QuoteFollow account.</p>
    </div>

    @if (session('status'))
        <div role="status" class="mt-6 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-10 space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-semibold text-slate-900">Email</label>
            <div class="relative mt-2">
                <x-ui-icon name="envelope" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="Enter your email address" class="block w-full rounded-xl border-0 bg-white py-3 pr-4 pl-12 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset">
            </div>
            @error('email') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="block text-sm font-semibold text-slate-900">Password</label>
            <div class="relative mt-2">
                <x-ui-icon name="lock" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" required autocomplete="current-password" placeholder="Enter your password" class="block w-full rounded-xl border-0 bg-white py-3 pr-12 pl-12 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset">
                <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide password' : 'Show password'" class="absolute top-1/2 right-3 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                    <x-ui-icon name="eye" class="size-5" x-show="! show" />
                    <x-ui-icon name="eye-slash" class="size-5" x-show="show" x-cloak />
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-4">
            <label class="flex items-center gap-3 text-sm text-slate-700">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 accent-violet-600"> Keep me signed in
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-violet-700 hover:underline">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[linear-gradient(90deg,#8b3dff,#6d28ff)] px-4 py-3.5 text-lg font-semibold text-white shadow-xl shadow-violet-600/40 ring-1 ring-white/20 ring-inset transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">
            Sign in <x-ui-icon name="arrow-right" class="size-5" />
        </button>
    </form>

    <div class="mt-8 flex items-center gap-4 text-sm text-slate-400" aria-hidden="true">
        <span class="h-px flex-1 bg-slate-200"></span>or<span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <p class="mt-6 text-center text-sm text-slate-600">
        New to QuoteFollow? <a href="{{ route('register') }}" class="font-semibold text-violet-700 hover:underline">Create an account</a>
    </p>
</x-layouts.sign-in>

<x-layouts.sign-in title="Create your account">
    <div class="text-center">
        <h1 class="text-[58px] leading-none font-bold tracking-tight text-slate-900">Create your account</h1>
        <p class="mt-4 text-[21px] text-slate-500">You'll be the owner. Invite your team afterwards.</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="mt-10 space-y-5" x-data="{ show: false }">
        @csrf
        @foreach ([
            'business_name' => ['Business name', 'text', 'organization', 'building', 'e.g. Dallas HVAC'],
            'name' => ['Your name', 'text', 'name', 'user', 'Enter your full name'],
            'email' => ['Email', 'email', 'username', 'envelope', 'Enter your email address'],
        ] as $key => [$fieldLabel, $type, $autocomplete, $icon, $placeholder])
            <div>
                <label for="{{ $key }}" class="block text-sm font-semibold text-slate-900">{{ $fieldLabel }}</label>
                <div class="relative mt-2">
                    <x-ui-icon :name="$icon" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
                    <input id="{{ $key }}" name="{{ $key }}" type="{{ $type }}" value="{{ old($key) }}" required autocomplete="{{ $autocomplete }}" placeholder="{{ $placeholder }}"
                           class="block w-full rounded-xl border-0 bg-white py-3 pr-4 pl-12 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset">
                </div>
                @error($key) <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div>
            <label for="password" class="block text-sm font-semibold text-slate-900">Password</label>
            <div class="relative mt-2">
                <x-ui-icon name="lock" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" required autocomplete="new-password" placeholder="Create a password" class="block w-full rounded-xl border-0 bg-white py-3 pr-12 pl-12 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset">
                <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide password' : 'Show password'" class="absolute top-1/2 right-3 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                    <x-ui-icon name="eye" class="size-5" x-show="! show" />
                    <x-ui-icon name="eye-slash" class="size-5" x-show="show" x-cloak />
                </button>
            </div>
            @error('password') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-semibold text-slate-900">Confirm password</label>
            <div class="relative mt-2">
                <x-ui-icon name="lock" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
                <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'" required autocomplete="new-password" placeholder="Re-enter your password" class="block w-full rounded-xl border-0 bg-white py-3 pr-4 pl-12 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset">
            </div>
        </div>

        <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[linear-gradient(90deg,#8b3dff,#6d28ff)] px-4 py-3.5 text-lg font-semibold text-white shadow-xl shadow-violet-600/40 ring-1 ring-white/20 ring-inset transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">
            Create account <x-ui-icon name="arrow-right" class="size-5" />
        </button>
    </form>

    <div class="mt-8 flex items-center gap-4 text-sm text-slate-400" aria-hidden="true">
        <span class="h-px flex-1 bg-slate-200"></span>or<span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account? <a href="{{ route('login') }}" class="font-semibold text-violet-700 hover:underline">Sign in</a>
    </p>
</x-layouts.sign-in>

@use('App\Support\AuthForm')
<x-layouts.sign-in title="Sign in">

    <div class="text-center">
        <h1 class="text-[40px] leading-none font-extrabold tracking-tight sm:text-[52px]">Welcome back</h1>
        <p class="mt-[18px] text-lg text-[#56536f] sm:text-[22px]">Sign in to your {{ config('app.name', 'QuoteFollow') }} account.</p>
    </div>

    @if (session('status'))
        <div role="status" class="mt-8 rounded-[10px] bg-violet-50 p-3.5 text-[15px] text-violet-900 ring-1 ring-violet-200 ring-inset">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-11">
        @csrf

        <div>
            <label for="email" class="{{ AuthForm::LABEL }}">Email</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="envelope" class="{{ AuthForm::ICON }}" />
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="Enter your email address" class="{{ AuthForm::INPUT }}">
            </div>
            @error('email') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div class="mt-[27px]" x-data="{ show: false }">
            <label for="password" class="{{ AuthForm::LABEL }}">Password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" required autocomplete="current-password" placeholder="Enter your password" class="{{ AuthForm::INPUT }} pr-14">
                <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide password' : 'Show password'" aria-label="Show password" class="{{ AuthForm::TOGGLE }}">
                    <x-ui-icon name="eye-slash" class="size-6 stroke-[1.8]" x-show="! show" />
                    <x-ui-icon name="eye" class="size-6 stroke-[1.8]" x-show="show" x-cloak />
                </button>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-between gap-4">
            <label class="flex items-center gap-3 text-[15px] whitespace-nowrap text-[#2b2847] sm:gap-3.5 sm:text-[17px]">
                <input type="checkbox" name="remember" value="1" class="size-[21px] rounded-[5px] border-[#c9c6d6] accent-violet-600"> Keep me signed in
            </label>
            <a href="{{ route('password.request') }}" class="text-[15px] font-medium whitespace-nowrap text-[#6d28d9] hover:underline sm:text-[17px]">Forgot password?</a>
        </div>

        <button type="submit" class="{{ AuthForm::SUBMIT }} mt-[29px]" style="{{ AuthForm::SUBMIT_STYLE }}">
            Sign in <x-ui-icon name="arrow-right" class="size-[22px] stroke-2" />
        </button>
    </form>

    <div class="mt-[31px] flex items-center gap-8 text-base text-[#4b4866]" aria-hidden="true">
        <span class="h-px flex-1 bg-[#e3e1ec]"></span>or<span class="h-px flex-1 bg-[#e3e1ec]"></span>
    </div>

    <p class="mt-[22px] text-center text-[17px] text-[#4b4866]">
        New to {{ config('app.name', 'QuoteFollow') }}? <a href="{{ route('register') }}" class="ml-1.5 font-bold text-[#6d28d9] hover:underline">Create an account</a>
    </p>
</x-layouts.sign-in>

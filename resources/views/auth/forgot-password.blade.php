@use('App\Support\AuthForm')
<x-layouts.sign-in title="Forgot password">

    <div class="text-center">
        <h1 class="text-[36px] leading-none font-extrabold tracking-tight sm:text-[46px]">Forgot password?</h1>
        <p class="mt-[18px] text-lg text-[#56536f] sm:text-[20px]">Enter your email and we'll send you a link to reset it.</p>
    </div>

    @if (session('status'))
        <div role="status" class="mt-8 rounded-[10px] bg-violet-50 p-3.5 text-[15px] text-violet-900 ring-1 ring-violet-200 ring-inset">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-11">
        @csrf
        <label for="email" class="{{ AuthForm::LABEL }}">Email</label>
        <div class="relative mt-[11px]">
            <x-ui-icon name="envelope" class="{{ AuthForm::ICON }}" />
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="Enter your email address" class="{{ AuthForm::INPUT }}">
        </div>
        @error('email') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

        <button type="submit" class="{{ AuthForm::SUBMIT }} mt-[30px]" style="{{ AuthForm::SUBMIT_STYLE }}">
            Send reset link <x-ui-icon name="arrow-right" class="size-[22px] stroke-2" />
        </button>
    </form>

    <p class="mt-8 text-center text-[17px] text-[#4b4866]">
        Remembered it? <a href="{{ route('login') }}" class="ml-1.5 {{ AuthForm::LINK }}">Back to sign in</a>
    </p>
</x-layouts.sign-in>

@use('App\Support\AuthForm')
<x-layouts.sign-in title="Reset password">

    <div class="text-center">
        <h1 class="text-[36px] leading-none font-extrabold tracking-tight sm:text-[46px]">Set a new password</h1>
        <p class="mt-[18px] text-lg text-[#56536f] sm:text-[20px]">Choose a new password for your account.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="mt-10 space-y-[22px]" x-data="{ show: false }">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="{{ AuthForm::LABEL }}">Email</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="envelope" class="{{ AuthForm::ICON }}" />
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username" placeholder="Enter your email address" class="{{ AuthForm::INPUT }}">
            </div>
            @error('email') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="{{ AuthForm::LABEL }}">New password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" required autofocus autocomplete="new-password" placeholder="Create a new password" class="{{ AuthForm::INPUT }} pr-14">
                <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide password' : 'Show password'" aria-label="Show password" class="{{ AuthForm::TOGGLE }}">
                    <x-ui-icon name="eye-slash" class="size-6 stroke-[1.8]" x-show="! show" />
                    <x-ui-icon name="eye" class="size-6 stroke-[1.8]" x-show="show" x-cloak />
                </button>
            </div>
            @error('password') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="{{ AuthForm::LABEL }}">Confirm new password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" placeholder="Re-enter the new password" class="{{ AuthForm::INPUT }}">
            </div>
        </div>

        <button type="submit" class="{{ AuthForm::SUBMIT }} mt-[30px]!" style="{{ AuthForm::SUBMIT_STYLE }}">
            Reset password <x-ui-icon name="arrow-right" class="size-[22px] stroke-2" />
        </button>
    </form>
</x-layouts.sign-in>

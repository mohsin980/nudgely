@use('App\Support\AuthForm')
<x-layouts.sign-in title="Create your account">

    <div class="text-center">
        <h1 class="text-[36px] leading-none font-extrabold tracking-tight sm:text-[46px]">Create your account</h1>
        <p class="mt-[18px] text-lg text-[#56536f] sm:text-[22px]">You'll be the owner. Invite your team afterwards.</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="mt-10 space-y-[22px]" x-data="{ show: false }">
        @csrf
        @foreach ([
            'business_name' => ['Business name', 'text', 'organization', 'building', 'e.g. Dallas HVAC'],
            'name' => ['Your name', 'text', 'name', 'user', 'Enter your full name'],
            'email' => ['Email', 'email', 'username', 'envelope', 'Enter your email address'],
        ] as $key => [$fieldLabel, $type, $autocomplete, $icon, $placeholder])
            <div>
                <label for="{{ $key }}" class="{{ AuthForm::LABEL }}">{{ $fieldLabel }}</label>
                <div class="relative mt-[11px]">
                    <x-ui-icon :name="$icon" class="{{ AuthForm::ICON }}" />
                    <input id="{{ $key }}" name="{{ $key }}" type="{{ $type }}" value="{{ old($key) }}" required autocomplete="{{ $autocomplete }}" placeholder="{{ $placeholder }}" class="{{ AuthForm::INPUT }}">
                </div>
                @error($key) <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div>
            <label for="password" class="{{ AuthForm::LABEL }}">Password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" placeholder="Create a password" class="{{ AuthForm::INPUT }} pr-14">
                <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide password' : 'Show password'" aria-label="Show password" class="{{ AuthForm::TOGGLE }}">
                    <x-ui-icon name="eye-slash" class="size-6 stroke-[1.8]" x-show="! show" />
                    <x-ui-icon name="eye" class="size-6 stroke-[1.8]" x-show="show" x-cloak />
                </button>
            </div>
            @error('password') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="{{ AuthForm::LABEL }}">Confirm password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" placeholder="Re-enter your password" class="{{ AuthForm::INPUT }}">
            </div>
        </div>

        <button type="submit" class="{{ AuthForm::SUBMIT }} mt-[30px]!" style="{{ AuthForm::SUBMIT_STYLE }}">
            Create account <x-ui-icon name="arrow-right" class="size-[22px] stroke-2" />
        </button>
    </form>

    <div class="mt-[31px] flex items-center gap-8 text-base text-[#4b4866]" aria-hidden="true">
        <span class="h-px flex-1 bg-[#e3e1ec]"></span>or<span class="h-px flex-1 bg-[#e3e1ec]"></span>
    </div>

    <p class="mt-[22px] text-center text-[17px] text-[#4b4866]">
        Already have an account? <a href="{{ route('login') }}" class="ml-1.5 {{ AuthForm::LINK }}">Sign in</a>
    </p>
</x-layouts.sign-in>

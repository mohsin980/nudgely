@use('App\Support\AuthForm')
<x-layouts.sign-in title="Create your account">
    <x-slot:panel>
        <p class="text-[1.9cqw] font-bold tracking-[0.12em] text-[#cfc4ff] uppercase">How it works</p>
        <h2 class="mt-[1.8cqw] text-[6.4cqw] leading-[1.05] font-extrabold tracking-tight">Set up in<br><span class="bg-linear-to-b from-white to-[#c9b8ff] bg-clip-text pb-[0.08em] text-transparent">three steps.</span></h2>

        <ol class="mt-[4.6cqw]">
            @foreach ([
                ['Create your account', 'Your business and your sign-in.'],
                ['Add your first customer', 'Name and email are enough.'],
                ['Send an estimate', 'Follow-ups run until the customer replies.'],
            ] as $index => [$stepTitle, $stepText])
                <li class="relative flex items-start gap-[2.3cqw] @if (! $loop->last) pb-[5.4cqw] @endif">
                    @unless ($loop->last)
                        <span aria-hidden="true" class="absolute top-[6.7cqw] bottom-[0.8cqw] left-[2.85cqw] w-[0.26cqw] rounded-full bg-white/25"></span>
                    @endunless
                    <span @class([
                        'grid size-[5.9cqw] shrink-0 place-items-center rounded-[1.8cqw] text-[2.4cqw] font-extrabold',
                        'bg-white text-[#5b2ee6] shadow-[0_1.3cqw_3cqw_rgba(30,10,110,0.35)]' => $index === 0,
                        'bg-white/15 text-white ring-1 ring-white/25 ring-inset' => $index > 0,
                    ])>{{ $index + 1 }}</span>
                    <div class="pt-[0.3cqw]">
                        <p class="text-[2.6cqw] leading-tight font-bold">{{ $stepTitle }}</p>
                        <p class="mt-[0.8cqw] text-[1.95cqw] text-[#ddd6ff]">{{ $stepText }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </x-slot:panel>

    <div class="text-center">
        <h1 class="text-[36px] leading-none font-extrabold tracking-tight sm:text-[46px]">Create your account</h1>
        <p class="mt-[18px] text-lg text-[#56536f] sm:text-[20px]">You'll be the owner. Invite your team afterwards.</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="mt-10 space-y-[22px]"
          x-data="{
              show: false,
              pw: '',
              get score() {
                  const p = this.pw;
                  if (! p) return 0;
                  let s = 0;
                  if (p.length >= 8) s++;
                  if (p.length >= 12) s++;
                  if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
                  if (/[0-9]/.test(p) || /[^A-Za-z0-9]/.test(p)) s++;
                  return Math.max(1, s);
              },
              get strength() { return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.score]; },
          }">
        @csrf

        <div class="grid gap-[22px] sm:grid-cols-2 sm:gap-4">
            @foreach ([
                'business_name' => ['Business name', 'organization', 'building', 'Dallas HVAC'],
                'name' => ['Your name', 'name', 'user', 'Full name'],
            ] as $key => [$fieldLabel, $autocomplete, $icon, $placeholder])
                <div>
                    <label for="{{ $key }}" class="{{ AuthForm::LABEL }}">{{ $fieldLabel }}</label>
                    <div class="relative mt-[11px]">
                        <x-ui-icon :name="$icon" class="{{ AuthForm::ICON }}" />
                        <input id="{{ $key }}" name="{{ $key }}" type="text" value="{{ old($key) }}" required autocomplete="{{ $autocomplete }}" placeholder="{{ $placeholder }}" class="{{ AuthForm::INPUT }}">
                    </div>
                    @error($key) <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>

        <div>
            <label for="email" class="{{ AuthForm::LABEL }}">Email</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="envelope" class="{{ AuthForm::ICON }}" />
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" placeholder="Enter your email address" class="{{ AuthForm::INPUT }}">
            </div>
            @error('email') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="{{ AuthForm::LABEL }}">Password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password" name="password" x-model="pw" :type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" placeholder="Create a password" aria-describedby="password-strength" class="{{ AuthForm::INPUT }} pr-14">
                <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide passwords' : 'Show passwords'" aria-label="Show passwords" class="{{ AuthForm::TOGGLE }}">
                    <x-ui-icon name="eye-slash" class="size-6 stroke-[1.8]" x-show="! show" />
                    <x-ui-icon name="eye" class="size-6 stroke-[1.8]" x-show="show" x-cloak />
                </button>
            </div>
            <div class="mt-2.5 grid grid-cols-4 gap-1.5" aria-hidden="true">
                @foreach ([1, 2, 3, 4] as $bar)
                    <span class="h-[5px] rounded-full bg-[#e7e4f0] transition-colors" :class="score >= {{ $bar }} && (score <= 1 ? 'bg-amber-500!' : 'bg-violet-600!')"></span>
                @endforeach
            </div>
            <p id="password-strength" class="mt-1.5 flex justify-between text-[13px]" aria-live="polite">
                <span class="font-bold" :class="score <= 1 ? 'text-amber-700' : 'text-violet-700'" x-text="strength ? strength + ' password' : ''"></span>
                <span class="text-[#8a87a0]">Longer is stronger</span>
            </p>
            @error('password') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="{{ AuthForm::LABEL }}">Confirm password</label>
            <div class="relative mt-[11px]">
                <x-ui-icon name="lock" class="{{ AuthForm::ICON }}" />
                <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" placeholder="Re-enter your password" class="{{ AuthForm::INPUT }}">
            </div>
        </div>

        <button type="submit" class="{{ AuthForm::SUBMIT }} mt-[28px]!" style="{{ AuthForm::SUBMIT_STYLE }}">
            Create account <x-ui-icon name="arrow-right" class="size-[22px] stroke-2" />
        </button>
    </form>

    <p class="mt-7 text-center text-[17px] text-[#4b4866]">
        Already have an account? <a href="{{ route('login') }}" class="ml-1.5 {{ AuthForm::LINK }}">Sign in</a>
    </p>
</x-layouts.sign-in>

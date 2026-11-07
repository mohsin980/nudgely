@use('App\Support\AuthForm')
<x-layouts.sign-in title="Confirm your email">

    <div class="text-center">
        <span class="mx-auto grid size-16 place-items-center rounded-2xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 ring-inset"><x-ui-icon name="envelope" class="size-8" /></span>
        <h1 class="mt-6 text-[36px] leading-none font-extrabold tracking-tight sm:text-[46px]">Check your email</h1>
        <p class="mt-[18px] text-lg text-[#56536f] sm:text-[20px]">We sent a link to <strong class="font-semibold text-slate-900">{{ $email }}</strong>. Click it to confirm your address and start using QuoteFlow.</p>
    </div>

    @if (session('status'))
        <div role="status" class="mt-8 rounded-[10px] bg-violet-50 p-3.5 text-[15px] text-violet-900 ring-1 ring-violet-200 ring-inset">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mt-11">
        @csrf
        <button type="submit" class="{{ AuthForm::SUBMIT }}" style="{{ AuthForm::SUBMIT_STYLE }}">
            Send the link again <x-ui-icon name="arrow-right" class="size-[22px] stroke-2" />
        </button>
    </form>

    <p class="mt-6 text-center text-[15px] text-[#6b6886]">Can't find it? Check your spam folder.</p>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-[17px] {{ AuthForm::LINK }}">Wrong address? Sign out</button>
    </form>
</x-layouts.sign-in>

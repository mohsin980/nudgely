{{-- Search, notifications and the account menu above the wizard card. --}}
@php($user = auth()->user())
@php($initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''))
@php($unread = $user->unreadNotifications()->count())
<div class="flex items-center gap-3">
    <form method="GET" action="{{ route('customers.index') }}" role="search" class="relative hidden min-w-0 flex-1 sm:block sm:max-w-md">
        <label for="wizard-search" class="sr-only">Search customers</label>
        <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-500" />
        <input id="wizard-search" type="search" name="search" placeholder="Search anything…" class="block h-12 w-full rounded-2xl border-0 bg-white/90 pr-4 pl-12 text-[15px] text-slate-900 shadow-[0_6px_20px_-12px_rgba(76,29,149,0.3)] ring-1 ring-white placeholder:text-slate-500 focus:ring-2 focus:ring-violet-500">
    </form>
    <div class="ml-auto flex items-center gap-3">
        <a href="{{ route('settings.notifications') }}" class="relative grid size-11 place-items-center rounded-full text-slate-700 transition hover:bg-white/80" title="Notifications">
            <x-ui-icon name="bell" class="size-6" />
            @if ($unread)<span class="absolute top-2 right-2.5 size-2.5 rounded-full bg-red-500 ring-2 ring-[#f7f6fc]"></span>@endif
            <span class="sr-only">Notifications{{ $unread ? ': '.$unread.' unread' : '' }}</span>
        </a>
        <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
            <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu" class="flex items-center gap-2.5 rounded-full py-1 pr-2 pl-1 transition hover:bg-white/80">
                <span aria-hidden="true" class="grid size-11 place-items-center rounded-full bg-linear-to-br from-violet-600 to-violet-500 text-[15px] font-semibold text-white">{{ $initials }}</span>
                <span class="hidden text-[15px] font-semibold text-slate-900 sm:block">{{ $user->name }}</span>
                <x-ui-icon name="chevron-down" class="size-4 text-slate-500" />
            </button>
            <div x-show="open" x-cloak x-transition.origin.top.right role="menu" class="absolute right-0 z-30 mt-2 w-52 overflow-hidden rounded-2xl bg-white p-1.5 shadow-[0_18px_40px_-14px_rgba(76,29,149,0.35)] ring-1 ring-slate-200/70">
                <a href="{{ route('settings.security') }}" role="menuitem" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"><x-ui-icon name="user" class="size-4" />Account</a>
                @can('manage-billing')<a href="{{ route('settings.billing') }}" role="menuitem" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"><x-ui-icon name="dollar" class="size-4" />Billing</a>@endcan
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" role="menuitem" class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50"><x-ui-icon name="logout" class="size-4" />Sign out</button>
                </form>
            </div>
        </div>
    </div>
</div>

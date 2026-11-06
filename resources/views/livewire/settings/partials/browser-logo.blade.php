{{-- A small mark for the browser of a session. Expects $browser ("Chrome", "Safari", …). Decorative. --}}
@php($tones = ['Firefox' => 'from-orange-400 to-rose-500', 'Safari' => 'from-sky-400 to-blue-600', 'Edge' => 'from-cyan-400 to-emerald-500', 'Opera' => 'from-rose-500 to-red-600'])
@if ($browser === 'Chrome')
    <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-full bg-white shadow-xs ring-1 ring-slate-200">
        <span class="grid size-8 place-items-center rounded-full" style="background: conic-gradient(from -60deg, #ea4335 0 120deg, #fbbc04 0 240deg, #34a853 0 360deg);">
            <span class="grid size-4 place-items-center rounded-full bg-white"><span class="size-2.5 rounded-full bg-[#4285f4]"></span></span>
        </span>
    </span>
@else
    <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-full bg-linear-to-br {{ $tones[$browser] ?? 'from-slate-300 to-slate-500' }} text-white shadow-xs">
        <x-ui-icon name="globe" class="size-6" />
    </span>
@endif

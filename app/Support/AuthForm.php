<?php

namespace App\Support;

/**
 * Shared Tailwind classes for the sign-in, register and password reset forms.
 */
final class AuthForm
{
    public const LABEL = 'block text-[17px] font-bold text-[#14113b]';

    public const INPUT = 'block h-[52px] w-full rounded-[10px] border-0 bg-white pr-4 pl-[62px] text-[17px] text-[#14113b] shadow-none ring-[1.5px] ring-[#d9d6e4] ring-inset placeholder:text-[#7d7a92] focus:ring-2 focus:ring-violet-600 focus:ring-inset';

    public const ICON = 'pointer-events-none absolute top-1/2 left-[22px] size-6 -translate-y-1/2 stroke-[1.8] text-[#3d3a5c]';

    public const TOGGLE = 'absolute top-1/2 right-3 grid size-10 -translate-y-1/2 place-items-center rounded-lg text-[#3d3a5c] hover:text-[#14113b] focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500';

    public const SUBMIT = 'flex h-[52px] w-full items-center justify-center gap-3 rounded-[10px] text-[21px] font-bold text-white shadow-[0_14px_30px_-10px_rgba(109,40,217,0.6)] transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2';

    public const SUBMIT_STYLE = 'background: linear-gradient(90deg, #6a2cf2 0%, #7c3aed 45%, #a07bff 100%);';

    public const LINK = 'font-bold text-[#6d28d9] hover:underline';
}

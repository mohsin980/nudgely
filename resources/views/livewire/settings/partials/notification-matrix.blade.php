{{-- On/off switches per notification type and channel. Expects $model ('mine' or 'defaults'), $types, $channels and the matching array. --}}
@php($look = [
    'customer_reply' => ['chat', 'bg-violet-50 text-violet-600'],
    'estimate_accepted' => ['check', 'bg-emerald-50 text-emerald-600'],
    'estimate_declined' => ['close', 'bg-rose-50 text-rose-500'],
    'automation_failed' => ['exclamation', 'bg-orange-50 text-orange-500'],
    'follow_up_due' => ['clock', 'bg-sky-50 text-sky-600'],
    'task_assigned' => ['clipboard-check', 'bg-violet-50 text-violet-600'],
    'trial_ending' => ['sparkles', 'bg-amber-50 text-amber-500'],
    'payment_failed' => ['dollar', 'bg-violet-50 text-violet-600'],
    'payment_recovered' => ['check-circle', 'bg-emerald-50 text-emerald-600'],
    'trial_ended' => ['calendar', 'bg-teal-50 text-teal-600'],
    'subscription_cancelling' => ['close', 'bg-rose-50 text-rose-500'],
    'subscription_ended' => ['dollar', 'bg-indigo-50 text-indigo-600'],
    'subscription_renewed' => ['arrow-path', 'bg-emerald-50 text-emerald-600'],
    'billing_restricted' => ['lock', 'bg-amber-50 text-amber-600'],
    'email_account_needs_reconnect' => ['envelope', 'bg-sky-50 text-sky-600'],
])
@php($channelIcons = ['in_app' => 'computer', 'email' => 'envelope'])
<div class="overflow-x-auto">
    <table class="min-w-full" data-matrix="{{ $model }}">
        <thead>
            <tr class="bg-slate-50/80 text-left">
                <th scope="col" class="rounded-l-xl py-3 pr-4 pl-4 text-[15px] font-semibold text-slate-700">Notification</th>
                @foreach ($channels as $channel)
                    <th scope="col" @class(['w-36 px-4 py-3 text-center text-[15px] font-semibold whitespace-nowrap text-slate-700', 'rounded-r-xl' => $loop->last])>
                        <span class="inline-flex items-center gap-2"><x-ui-icon :name="$channelIcons[$channel->value] ?? 'bell'" class="size-5 text-violet-600" />{{ $channel->label() }}</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($types as $type)
                @php([$icon, $tone] = $look[$type->value] ?? ['bell', 'bg-violet-50 text-violet-600'])
                <tr class="transition-colors hover:bg-violet-50/30">
                    <th scope="row" class="py-2.5 pr-4 pl-4 text-left font-normal">
                        <span class="flex items-center gap-4">
                            <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-xl {{ $tone }}"><x-ui-icon :name="$icon" class="size-6 stroke-[1.8]" /></span>
                            <span class="min-w-0">
                                <span class="block text-[15px] font-semibold text-slate-900">{{ $type->label() }}</span>
                                <span class="block text-[13px] text-slate-500">{{ $type->description() }}</span>
                            </span>
                        </span>
                    </th>
                    @foreach ($channels as $channel)
                        <td class="px-4 py-2.5 text-center">
                            <label class="group relative inline-flex cursor-pointer items-center justify-center">
                                <span class="sr-only">{{ $type->label() }} — {{ $channel->label() }}</span>
                                <input type="checkbox" wire:model="{{ $model }}.{{ $type->value }}.{{ $channel->value }}" data-pref="{{ $model }}.{{ $type->value }}.{{ $channel->value }}" class="peer sr-only">
                                <span aria-hidden="true" class="relative inline-flex h-7 w-12 items-center rounded-full bg-slate-200 shadow-[inset_0_1px_2px_rgba(15,23,42,0.12)] transition-colors peer-checked:bg-linear-to-r peer-checked:from-violet-700 peer-checked:to-violet-500 peer-focus-visible:ring-2 peer-focus-visible:ring-violet-500 peer-focus-visible:ring-offset-2 after:absolute after:left-0.5 after:size-6 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"></span>
                            </label>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

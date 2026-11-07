<x-settings.shell title="Roles & Permissions" description="Three fixed roles keep things simple. The owner changes someone's role under Team Members.">
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($roles as $role)
            @php([$tone, $icon] = match ($role) { \App\Enums\OrganizationRole::Owner => ['bg-violet-100 text-violet-600', 'crown'], \App\Enums\OrganizationRole::Manager => ['bg-sky-100 text-sky-600', 'user'], default => ['bg-emerald-100 text-emerald-600', 'user'] })
            @php($titleTone = match ($role) { \App\Enums\OrganizationRole::Owner => 'text-violet-700', \App\Enums\OrganizationRole::Manager => 'text-sky-700', default => 'text-emerald-700' })
            <div class="flex flex-col items-center rounded-2xl bg-white p-5 text-center shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70" data-role="{{ $role->value }}">
                <span class="grid size-11 place-items-center rounded-full {{ $tone }}"><x-ui-icon :name="$icon" class="size-5" /></span>
                <p class="mt-3 text-base font-bold {{ $titleTone }}">{{ $role->label() }} <span class="text-sm font-normal text-slate-500">· {{ $counts[$role->value] ?? 0 }} active</span></p>
                <p class="mt-1 text-sm text-slate-500">{{ $role->description() }}</p>
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl bg-white shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70">
        <table class="min-w-full divide-y divide-slate-200 text-sm" data-section="matrix">
            <caption class="sr-only">What each role can do</caption>
            <thead class="bg-white">
                <tr>
                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-900">Permission</th>
                    @foreach ($roles as $role)<th scope="col" class="px-5 py-3 text-center font-bold text-slate-900">{{ $role->label() }}</th>@endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row" class="px-5 py-2.5 text-left font-normal text-slate-800">{{ $row['label'] }}</th>
                        @foreach ($roles as $role)
                            <td class="px-5 py-2.5 text-center">
                                @if ($row['roles'][$role->value])<x-ui-icon name="check" class="inline size-4 stroke-2 text-emerald-600" /><span class="sr-only">Allowed</span>@else<span class="text-slate-400" aria-hidden="true">—</span><span class="sr-only">Not allowed</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-settings.shell>

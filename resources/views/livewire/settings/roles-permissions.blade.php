<x-settings.shell title="Roles & Permissions" description="Three fixed roles keep things simple. The owner changes someone's role under Team Members.">
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($roles as $role)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs sm:p-5" data-role="{{ $role->value }}">
                <p class="font-semibold text-slate-900">{{ $role->label() }} <span class="text-sm font-normal text-slate-500">· {{ $counts[$role->value] ?? 0 }} active</span></p>
                <p class="mt-1 text-sm text-slate-500">{{ $role->description() }}</p>
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-xs">
        <table class="min-w-full divide-y divide-slate-200 text-sm" data-section="matrix">
            <caption class="sr-only">What each role can do</caption>
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-4 py-2 text-left font-semibold text-slate-900">Permission</th>
                    @foreach ($roles as $role)<th scope="col" class="px-4 py-2 text-center font-semibold text-slate-900">{{ $role->label() }}</th>@endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row" class="px-4 py-2 text-left font-normal text-slate-800">{{ $row['label'] }}</th>
                        @foreach ($roles as $role)
                            <td class="px-4 py-2 text-center">
                                @if ($row['roles'][$role->value])<span class="font-semibold text-emerald-700" aria-hidden="true">✓</span><span class="sr-only">Allowed</span>@else<span class="text-slate-400" aria-hidden="true">—</span><span class="sr-only">Not allowed</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-settings.shell>

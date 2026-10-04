<x-settings.shell title="Roles & Permissions" description="Three fixed roles keep things simple. The owner changes someone's role under Team Members.">
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($roles as $role)
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-role="{{ $role->value }}">
                <p class="font-semibold text-gray-900">{{ $role->label() }} <span class="text-sm font-normal text-gray-500">· {{ $counts[$role->value] ?? 0 }} active</span></p>
                <p class="mt-1 text-sm text-gray-600">{{ $role->description() }}</p>
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm" data-section="matrix">
            <caption class="sr-only">What each role can do</caption>
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-4 py-2 text-left font-semibold text-gray-900">Permission</th>
                    @foreach ($roles as $role)<th scope="col" class="px-4 py-2 text-center font-semibold text-gray-900">{{ $role->label() }}</th>@endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row" class="px-4 py-2 text-left font-normal text-gray-800">{{ $row['label'] }}</th>
                        @foreach ($roles as $role)
                            <td class="px-4 py-2 text-center">
                                @if ($row['roles'][$role->value])<span class="font-semibold text-green-700" aria-hidden="true">✓</span><span class="sr-only">Allowed</span>@else<span class="text-gray-400" aria-hidden="true">—</span><span class="sr-only">Not allowed</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-settings.shell>

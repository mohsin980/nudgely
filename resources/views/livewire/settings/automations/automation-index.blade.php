<div class="space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Automations</h1>
            <p class="mt-1 text-sm text-gray-600">Rules that react to customer replies: WHEN something happens, IF conditions match, THEN take actions.</p>
        </div>
        <a href="{{ route('settings.automations.create') }}" wire:navigate class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
            New automation
        </a>
    </div>

    @if ($statusMessage)
        <div wire:key="status-{{ md5($statusMessage) }}" role="{{ $statusType === 'error' ? 'alert' : 'status' }}"
             @class([
                 'flex items-start justify-between gap-4 rounded-md p-4 text-sm',
                 'bg-green-50 text-green-800' => $statusType === 'success',
                 'bg-red-50 text-red-800' => $statusType === 'error',
             ])>
            <p>{{ $statusMessage }}</p>
            <button type="button" wire:click="$set('statusMessage', null)" class="shrink-0 font-medium hover:underline">Dismiss</button>
        </div>
    @endif

    {{-- Organization settings --}}
    <section aria-labelledby="settings-heading" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <h2 id="settings-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Settings</h2>
        <ul role="list" class="mt-3 divide-y divide-gray-100">
            @foreach ([
                'automations_enabled' => ['Automations', 'Run active automations when customers reply.'],
                'automatic_email_enabled' => ['Automatic emails', 'Allow automations to email customers from your verified business email.'],
                'require_approval_for_email' => ['Require approval for emails', 'Never send automated emails without approval. Turn off only if you trust your email automations.'],
            ] as $setting => [$label, $help])
                @php($on = (bool) $organization->{$setting})
                <li class="flex items-center justify-between gap-4 py-3" wire:key="setting-{{ $setting }}">
                    <div>
                        <p class="text-sm font-medium text-gray-900" id="setting-{{ $setting }}">{{ $label }}</p>
                        <p class="text-sm text-gray-600">{{ $help }}</p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-labelledby="setting-{{ $setting }}"
                            wire:click="toggleSetting('{{ $setting }}')" data-setting="{{ $setting }}"
                            @class([
                                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2',
                                'bg-indigo-600' => $on,
                                'bg-gray-200' => ! $on,
                            ])>
                        <span class="sr-only">{{ $on ? 'On' : 'Off' }}</span>
                        <span aria-hidden="true" @class(['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', 'translate-x-5' => $on, 'translate-x-0' => ! $on])></span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Automations --}}
    <section aria-labelledby="automations-heading" class="space-y-3">
        <h2 id="automations-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Your automations</h2>

        @if ($this->automations->isEmpty())
            <p class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center text-sm text-gray-600">No automations yet. Start from a template below or create your own.</p>
        @else
            <div class="relative overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase">
                        <tr>
                            <th scope="col" class="px-4 py-3">Name</th>
                            <th scope="col" class="px-4 py-3">Trigger</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">Last run</th>
                            <th scope="col" class="px-4 py-3">Created</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($this->automations as $automation)
                            <tr wire:key="automation-{{ $automation->id }}">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $automation->name }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $automation->trigger_type->label() }}</td>
                                <td class="px-4 py-3"><x-automation-status-badge :status="$automation->status" /></td>
                                <td class="px-4 py-3 text-gray-700">{{ $automation->last_run_at ? \Illuminate\Support\Carbon::parse($automation->last_run_at)->diffForHumans() : 'Never' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $automation->created_at->format('M j, Y') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('settings.automations.edit', $automation->id) }}" wire:navigate class="rounded-md bg-white px-2.5 py-1 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Edit</a>
                                        <a href="{{ route('settings.automations.runs', $automation->id) }}" wire:navigate class="rounded-md bg-white px-2.5 py-1 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Runs</a>
                                        @if ($automation->isActive())
                                            <button type="button" wire:click="pause({{ $automation->id }})" class="rounded-md bg-white px-2.5 py-1 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Pause</button>
                                        @else
                                            <button type="button" wire:click="activate({{ $automation->id }})" class="rounded-md bg-indigo-600 px-2.5 py-1 font-medium text-white hover:bg-indigo-500">Activate</button>
                                        @endif
                                        @if ($confirmingDeletionId === $automation->id)
                                            <button type="button" wire:click="delete" class="rounded-md bg-red-600 px-2.5 py-1 font-medium text-white hover:bg-red-500">Confirm delete</button>
                                            <button type="button" wire:click="cancelDelete" class="rounded-md px-2.5 py-1 font-medium text-gray-700 hover:underline">Cancel</button>
                                        @else
                                            <button type="button" wire:click="confirmDelete({{ $automation->id }})" class="rounded-md px-2.5 py-1 font-medium text-red-700 hover:underline">Delete</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Templates --}}
    <section aria-labelledby="templates-heading" class="space-y-3">
        <h2 id="templates-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Templates</h2>
        <ul role="list" class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->templates as $key => $template)
                <li wire:key="template-{{ $key }}" class="flex flex-col justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $template['name'] }}</p>
                        <p class="mt-1 text-sm text-gray-600">{{ $template['description'] }}</p>
                    </div>
                    <div>
                        <button type="button" wire:click="installTemplate('{{ $key }}')" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-indigo-700 ring-1 ring-indigo-200 ring-inset hover:bg-indigo-50">Use template</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</div>

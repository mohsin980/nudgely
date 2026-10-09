<x-settings.shell title="Email Settings" description="Configure the email address QuoteFlow will use when communicating with your customers.">
    @if (request()->query('from') === 'onboarding')
        <p><a href="{{ route('onboarding.show') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline" data-back-to-setup>← Back to setup</a></p>
    @endif
<div class="space-y-8">

    @if ($statusMessage)
        <div wire:key="status-{{ md5($statusMessage) }}"
             x-data="{ show: true }"
             x-show="show"
             x-init="setTimeout(() => show = false, 6000)"
             role="{{ $statusType === 'error' ? 'alert' : 'status' }}"
             @class([
                 'flex items-start justify-between gap-4 rounded-md p-4 text-sm',
                 'bg-green-50 text-green-800' => $statusType === 'success',
                 'bg-red-50 text-red-800' => $statusType === 'error',
                 'bg-blue-50 text-blue-800' => $statusType === 'info',
             ])>
            <p>{{ $statusMessage }}</p>
            <button type="button" wire:click="dismissStatus" class="shrink-0 rounded font-medium underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-current">
                Dismiss
            </button>
        </div>
    @endif

    {{-- Section 1: current sending email(s) --}}
    <section aria-labelledby="connections-heading" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="connections-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Current sending email</h2>

            @if ($this->connections->isNotEmpty() && ! $showForm)
                <button type="button" wire:click="create" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Add Business Email
                </button>
            @endif
        </div>

        @if ($this->connections->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                <h3 class="text-base font-semibold text-gray-900">Connect your business email</h3>
                <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">
                    Add your business domain and sender address so QuoteFlow can eventually send automated follow-ups from your own email.
                </p>

                @unless ($showForm)
                    <button type="button" wire:click="create" class="mt-6 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Add Business Email
                    </button>
                @endunless
            </div>
        @else
            <ul role="list" class="space-y-4">
                @foreach ($this->connections as $connection)
                    <li wire:key="connection-{{ $connection->id }}" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-base font-semibold text-gray-900">{{ $connection->sender_name }}</p>
                                    @if ($connection->is_default)
                                        <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-indigo-600/20 ring-inset">Default</span>
                                    @endif
                                </div>
                                <p class="truncate text-sm text-gray-700">{{ $connection->sender_email }}</p>

                                <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                    <dt class="text-gray-500">Domain:</dt>
                                    <dd class="truncate text-gray-900">{{ $connection->domain }}</dd>
                                    <dt class="text-gray-500">Status:</dt>
                                    <dd><x-email-verification-badge :status="$connection->verification_status" /></dd>
                                    @if ($connection->isVerified())
                                        <dt class="text-gray-500">Sender:</dt>
                                        <dd class="min-w-0 break-words text-gray-900">{{ $connection->sender_name }} &lt;{{ $connection->sender_email }}&gt;</dd>
                                    @endif
                                </dl>
                            </div>

                            <div class="flex flex-wrap gap-2 sm:justify-end">
                                <button type="button" wire:click="edit({{ $connection->id }})" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                    Edit
                                </button>

                                <button type="button"
                                        wire:click="openTestEmail({{ $connection->id }})"
                                        @disabled(! $connection->isVerified())
                                        @if (! $connection->isVerified()) title="Verify this domain before sending a test email." aria-describedby="default-hint-{{ $connection->id }}" @endif
                                        class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white">
                                    Send Test Email
                                </button>

                                @unless ($connection->is_default)
                                    <button type="button"
                                            wire:click="setDefault({{ $connection->id }})"
                                            wire:loading.attr="disabled"
                                            @disabled(! $connection->canBecomeDefault())
                                            @if (! $connection->canBecomeDefault()) title="Verify this domain before making it the default sender." aria-describedby="default-hint-{{ $connection->id }}" @endif
                                            class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white">
                                        Set as Default
                                    </button>
                                @endunless

                                <button type="button" wire:click="confirmDelete({{ $connection->id }})" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-red-700 ring-1 ring-gray-300 ring-inset hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                                    Delete
                                </button>
                            </div>
                        </div>

                        @if ($connection->isVerified() && $testEmailConnectionId === $connection->id)
                            <form wire:submit="sendTestEmail" class="mt-4 rounded-md bg-gray-50 p-4" novalidate>
                                <label for="testRecipient" class="block text-sm font-medium text-gray-900">Test Email Address</label>
                                <p id="testRecipient-hint" class="text-sm text-gray-600">
                                    We'll send a short test message from {{ $connection->sender_name }} &lt;{{ $connection->sender_email }}&gt;.
                                </p>
                                <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0 flex-1 sm:max-w-md">
                                        <input id="testRecipient"
                                               type="email"
                                               wire:model="testRecipient"
                                               autocomplete="email"
                                               placeholder="you@example.com"
                                               aria-describedby="testRecipient-hint @error('testRecipient') testRecipient-error @enderror"
                                               @error('testRecipient') aria-invalid="true" @enderror
                                               @class([
                                                   'block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2',
                                                   'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has('testRecipient'),
                                                   'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500' => ! $errors->has('testRecipient'),
                                               ])>
                                        @error('testRecipient')
                                            <p id="testRecipient-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <button type="submit" wire:loading.attr="disabled" wire:target="sendTestEmail" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                                            <span wire:loading.remove wire:target="sendTestEmail">Send Test Email</span>
                                            <span wire:loading wire:target="sendTestEmail">Sending…</span>
                                        </button>
                                        <button type="button" wire:click="cancelTestEmail" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        @endif

                        @unless ($connection->isVerified())
                            @php
                                $failed = $connection->verification_status === \App\Enums\EmailVerificationStatus::Failed;
                                $registered = $connection->isRegisteredWithProvider();
                                $showingRecords = $registered && $showingDnsRecordsFor === $connection->id;
                            @endphp

                            <div class="mt-4 rounded-md bg-gray-50 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-sm">
                                        <p class="font-medium text-gray-900">
                                            @if ($failed)
                                                Your domain could not be verified.
                                            @elseif ($registered)
                                                Waiting for your DNS records to be detected.
                                            @else
                                                Your domain has not been verified yet.
                                            @endif
                                        </p>
                                        @if ($failed && $connection->verification_error)
                                            <p class="text-red-700">{{ $connection->verification_error }}</p>
                                        @endif
                                        <p id="default-hint-{{ $connection->id }}" class="text-gray-600">
                                            Only verified emails can send email or become the default sender.
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        @if ($registered)
                                            <button type="button"
                                                    wire:click="toggleDnsRecords({{ $connection->id }})"
                                                    aria-expanded="{{ $showingRecords ? 'true' : 'false' }}"
                                                    aria-controls="dns-records-{{ $connection->id }}"
                                                    class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                                {{ $showingRecords ? 'Hide DNS Records' : 'View DNS Records' }}
                                            </button>
                                            <button type="button"
                                                    wire:click="checkVerification({{ $connection->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="checkVerification({{ $connection->id }})"
                                                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                                                <span wire:loading.remove wire:target="checkVerification({{ $connection->id }})">Check Verification</span>
                                                <span wire:loading wire:target="checkVerification({{ $connection->id }})">Checking…</span>
                                            </button>
                                        @else
                                            <button type="button"
                                                    wire:click="startVerification({{ $connection->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="startVerification({{ $connection->id }})"
                                                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                                                <span wire:loading.remove wire:target="startVerification({{ $connection->id }})">{{ $failed ? 'Try Again' : 'Verify Domain' }}</span>
                                                <span wire:loading wire:target="startVerification({{ $connection->id }})">Connecting…</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                @if ($showingRecords)
                                    @php($records = $connection->dnsRecords())
                                    @php($hasPriority = collect($records)->contains(fn ($record) => $record->priority !== null))

                                    <div id="dns-records-{{ $connection->id }}" class="mt-4 border-t border-gray-200 pt-4">
                                        <h3 class="text-sm font-semibold text-gray-900">DNS records</h3>
                                        <p class="mt-1 text-sm text-gray-600">
                                            Add these DNS records at the DNS provider for <span class="font-medium text-gray-900">{{ $connection->domain }}</span>.
                                            DNS changes can take some time to propagate.
                                        </p>
                                        <p class="mt-1 text-xs text-gray-500">
                                            Some DNS providers add your domain to the name automatically. If yours does, enter only the part before ".{{ $connection->domain }}".
                                        </p>

                                        @if ($records === [])
                                            <p class="mt-3 text-sm text-gray-700">
                                                We couldn't load the DNS records.
                                                <button type="button" wire:click="startVerification({{ $connection->id }})" class="font-medium text-indigo-700 underline-offset-2 hover:underline">Try again</button>
                                            </p>
                                        @else
                                            {{-- Wide screens: table --}}
                                            <div class="mt-3 hidden overflow-hidden rounded-md ring-1 ring-gray-200 sm:block">
                                                <table class="w-full table-fixed text-left text-sm">
                                                    <thead class="bg-white text-xs text-gray-500 uppercase">
                                                        <tr>
                                                            <th scope="col" class="w-20 px-3 py-2 font-medium">Type</th>
                                                            <th scope="col" class="px-3 py-2 font-medium">Name</th>
                                                            <th scope="col" class="w-2/5 px-3 py-2 font-medium">Value</th>
                                                            @if ($hasPriority)
                                                                <th scope="col" class="w-20 px-3 py-2 font-medium">Priority</th>
                                                            @endif
                                                            <th scope="col" class="w-28 px-3 py-2 font-medium">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200 bg-white">
                                                        @foreach ($records as $record)
                                                            <tr class="align-top">
                                                                <td class="px-3 py-3 font-mono text-gray-900">
                                                                    {{ $record->type }}
                                                                    <span class="block font-sans text-xs text-gray-500">{{ $record->purpose }}</span>
                                                                </td>
                                                                <td class="px-3 py-3">
                                                                    <div class="flex items-start gap-2">
                                                                        <span class="min-w-0 font-mono break-all text-gray-900">{{ $record->name }}</span>
                                                                        <x-copy-button :value="$record->name" :label="'Copy '.$record->purpose.' name'" />
                                                                    </div>
                                                                </td>
                                                                <td class="px-3 py-3">
                                                                    <div class="flex items-start gap-2">
                                                                        <span class="line-clamp-3 min-w-0 font-mono break-all text-gray-900" title="{{ $record->value }}">{{ $record->value }}</span>
                                                                        <x-copy-button :value="$record->value" :label="'Copy '.$record->purpose.' value'" />
                                                                    </div>
                                                                </td>
                                                                @if ($hasPriority)
                                                                    <td class="px-3 py-3 text-gray-900">{{ $record->priority ?? '—' }}</td>
                                                                @endif
                                                                <td class="px-3 py-3"><x-dns-record-status :verified="$record->verified" /></td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>

                                            {{-- Small screens: stacked cards --}}
                                            <ul role="list" class="mt-3 space-y-3 sm:hidden">
                                                @foreach ($records as $record)
                                                    <li class="rounded-md bg-white p-3 text-sm ring-1 ring-gray-200">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="font-mono font-medium text-gray-900">{{ $record->type }} <span class="font-sans font-normal text-gray-500">· {{ $record->purpose }}</span></span>
                                                            <x-dns-record-status :verified="$record->verified" />
                                                        </div>
                                                        <dl class="mt-2 space-y-2">
                                                            <div>
                                                                <dt class="text-xs text-gray-500">Name</dt>
                                                                <dd class="flex items-start justify-between gap-2"><span class="min-w-0 font-mono break-all">{{ $record->name }}</span><x-copy-button :value="$record->name" :label="'Copy '.$record->purpose.' name'" /></dd>
                                                            </div>
                                                            <div>
                                                                <dt class="text-xs text-gray-500">Value</dt>
                                                                <dd class="flex items-start justify-between gap-2"><span class="line-clamp-4 min-w-0 font-mono break-all">{{ $record->value }}</span><x-copy-button :value="$record->value" :label="'Copy '.$record->purpose.' value'" /></dd>
                                                            </div>
                                                            @if ($record->priority !== null)
                                                                <div>
                                                                    <dt class="text-xs text-gray-500">Priority</dt>
                                                                    <dd>{{ $record->priority }}</dd>
                                                                </div>
                                                            @endif
                                                        </dl>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Section 2: add / edit form --}}
    @if ($showForm)
        <section aria-labelledby="form-heading" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 id="form-heading" class="text-base font-semibold text-gray-900">
                {{ $editingConnectionId ? 'Edit business email' : 'Add business email' }}
            </h2>
            @if ($editingConnectionId)
                <p class="mt-1 text-sm text-gray-600">Changing the domain or sender email resets its verification status.</p>
            @endif

            <form wire:submit="save" class="mt-6 space-y-5" novalidate>
                @foreach ([
                    ['domain', 'Business domain', 'example.com', 'text', 'off'],
                    ['senderName', 'Sender name', 'Dallas Cooling', 'text', 'organization'],
                    ['senderEmail', 'Sender email', 'sales@example.com', 'email', 'email'],
                ] as [$field, $label, $placeholder, $type, $autocomplete])
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium text-gray-900">{{ $label }}</label>
                        <input id="{{ $field }}"
                               type="{{ $type }}"
                               wire:model="{{ $field }}"
                               placeholder="{{ $placeholder }}"
                               autocomplete="{{ $autocomplete }}"
                               @if ($loop->first) autofocus @endif
                               @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                               @class([
                                   'mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 sm:max-w-md',
                                   'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has($field),
                                   'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500' => ! $errors->has($field),
                               ])>
                        @error($field)
                            <p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="save">Save</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                    <button type="button" wire:click="cancel" wire:loading.attr="disabled" wire:target="save" class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeletionId && ($pendingDeletion = $this->connections->firstWhere('id', $confirmingDeletionId)))
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-gray-900/50 p-4 sm:items-center"
             x-data
             x-trap.noscroll="true"
             @keydown.escape.window="$wire.cancelDelete()">
            <div role="alertdialog" aria-modal="true" aria-labelledby="delete-title" aria-describedby="delete-description"
                 class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 id="delete-title" class="text-base font-semibold text-gray-900">Remove email connection</h2>
                <div id="delete-description" class="mt-2 space-y-2 text-sm text-gray-600">
                    <p>Are you sure you want to remove this email connection?</p>
                    <p class="font-medium text-gray-900">{{ $pendingDeletion->sender_email }}</p>
                    @if ($pendingDeletion->is_default)
                        <p class="rounded-md bg-amber-50 p-3 text-amber-800">
                            This is your default sender. Until you set another verified connection as default, QuoteFlow will have no default sending email.
                        </p>
                    @endif
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="cancelDelete" class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        Cancel
                    </button>
                    <button type="button" wire:click="delete" wire:loading.attr="disabled" wire:target="delete" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-60">
                        Remove
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
</x-settings.shell>

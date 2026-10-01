<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Email Settings</h1>
        <p class="mt-1 text-sm text-gray-600">Configure the email address QuoteFlow will use when communicating with your customers.</p>
    </div>

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
                                </dl>
                            </div>

                            <div class="flex flex-wrap gap-2 sm:justify-end">
                                <button type="button" wire:click="edit({{ $connection->id }})" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                    Edit
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

                        @unless ($connection->isVerified())
                            <div class="mt-4 flex flex-col gap-3 rounded-md bg-gray-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="text-sm">
                                    <p class="font-medium text-gray-900">
                                        {{ $connection->verification_status === \App\Enums\EmailVerificationStatus::Failed ? 'Your domain could not be verified.' : 'Your domain has not been verified yet.' }}
                                    </p>
                                    <p id="default-hint-{{ $connection->id }}" class="text-gray-600">
                                        Domain verification will be available soon. Only verified emails can become the default sender.
                                    </p>
                                </div>

                                {{-- Placeholder only: verification is not implemented yet and makes no requests. --}}
                                <button type="button" disabled class="shrink-0 cursor-not-allowed rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-500 opacity-60 ring-1 ring-gray-300 ring-inset">
                                    Verify Domain
                                </button>
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

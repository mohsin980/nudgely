<x-settings.shell title="Email" description="Configure the email address QuoteFollow will use when communicating with your customers.">
    @if (request()->query('from') === 'onboarding')
        <p><a href="{{ route('onboarding.show') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline" data-back-to-setup>← Back to setup</a></p>
    @endif
<div class="space-y-8">

    @if ($statusMessage)
        <div wire:key="status-{{ md5($statusMessage) }}"
             x-data="{ show: true }"
             x-show="show"
             x-init="setTimeout(() => show = false, 6000)"
             role="{{ $statusType === 'error' ? 'alert' : 'status' }}"
             @class([
                 'flex items-start justify-between gap-4 rounded-lg p-4 text-sm',
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

    {{-- Connect a mailbox: Gmail, Outlook/Hotmail or your own SMTP. No domain or DNS records are needed. --}}
    @php
        $googleReady = filled(config('email.oauth.google.client_id'));
        $microsoftReady = filled(config('email.oauth.microsoft.client_id'));
    @endphp
    <section aria-labelledby="accounts-heading" class="space-y-4">
        <div>
            <h2 id="accounts-heading" class="text-sm font-semibold text-slate-500">Connect your email account</h2>
            <p class="mt-1 text-sm text-slate-500">Send from the mailbox you already use. No domain or DNS setup is needed.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <p class="text-base font-semibold text-slate-900">Gmail</p>
                <p class="mt-1 flex-1 text-sm text-slate-500">Gmail or Google Workspace. You approve access with Google; we never see your password.</p>
                @if ($googleReady)
                    <a href="{{ route('settings.email.oauth.redirect', ['provider' => 'gmail']) }}" class="mt-4 inline-flex items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">Connect Google</a>
                @else
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">Not set up on this server yet.</p>
                @endif
            </div>

            <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <p class="text-base font-semibold text-slate-900">Outlook or Hotmail</p>
                <p class="mt-1 flex-1 text-sm text-slate-500">Outlook.com, Hotmail or Microsoft 365. You approve access with Microsoft.</p>
                @if ($microsoftReady)
                    <a href="{{ route('settings.email.oauth.redirect', ['provider' => 'microsoft']) }}" class="mt-4 inline-flex items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">Connect Microsoft</a>
                @else
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">Not set up on this server yet.</p>
                @endif
            </div>

            <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <p class="text-base font-semibold text-slate-900">Custom SMTP</p>
                <p class="mt-1 flex-1 text-sm text-slate-500">Any mail server that gives you a host, port and login. Encryption is required.</p>
                <button type="button" wire:click="openSmtpForm" class="mt-4 inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">Configure SMTP</button>
            </div>
        </div>

        @if ($showSmtpForm)
            <form wire:submit="connectSmtp" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="smtp-heading">
                <div>
                    <h3 id="smtp-heading" class="text-base font-semibold text-slate-900">Mail server settings</h3>
                    <p class="mt-1 text-sm text-slate-500">We test these settings before saving. For Gmail or Outlook SMTP, use an app password if two-step verification is on.</p>
                </div>

                @error('smtp')
                    <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
                @enderror

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="smtp-host" class="block text-sm font-medium text-slate-900">Server name</label>
                        <input id="smtp-host" type="text" wire:model="smtpHost" autocomplete="off" placeholder="smtp.example.com" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-xs focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="smtp-port" class="block text-sm font-medium text-slate-900">Port</label>
                        <input id="smtp-port" type="number" min="1" max="65535" wire:model="smtpPort" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-xs focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="smtp-encryption" class="block text-sm font-medium text-slate-900">Encryption</label>
                        <select id="smtp-encryption" wire:model="smtpEncryption" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-xs focus:border-violet-500 focus:ring-violet-500">
                            <option value="tls">STARTTLS (port 587)</option>
                            <option value="ssl">SSL/TLS (port 465)</option>
                        </select>
                    </div>
                    <div>
                        <label for="smtp-username" class="block text-sm font-medium text-slate-900">Email address (username)</label>
                        <input id="smtp-username" type="email" wire:model="smtpUsername" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-xs focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div>
                        <label for="smtp-password" class="block text-sm font-medium text-slate-900">Password or app password</label>
                        <input id="smtp-password" type="password" wire:model="smtpPassword" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-xs focus:border-violet-500 focus:ring-violet-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="smtp-sender-name" class="block text-sm font-medium text-slate-900">Name customers see</label>
                        <input id="smtp-sender-name" type="text" wire:model="smtpSenderName" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-xs focus:border-violet-500 focus:ring-violet-500">
                    </div>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="cancelSmtpForm" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="connectSmtp" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-violet-700 disabled:opacity-60">
                        <span wire:loading.remove wire:target="connectSmtp">Test and connect</span>
                        <span wire:loading wire:target="connectSmtp" role="status">Testing…</span>
                    </button>
                </div>
            </form>
        @endif

        @if ($this->accountConnections->isNotEmpty())
            <ul role="list" class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                @foreach ($this->accountConnections as $account)
                    @php
                        $label = match ($account->provider) { \App\Enums\EmailProvider::Gmail => 'Gmail', \App\Enums\EmailProvider::Microsoft => 'Outlook or Microsoft', default => 'Custom SMTP' };
                    @endphp
                    <li wire:key="account-{{ $account->id }}" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-base font-semibold text-slate-900">
                                <span class="min-w-0 break-all">{{ $account->sender_email }}</span>
                                @if ($account->is_default)
                                    <span class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-semibold text-violet-700">Default sender</span>
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-slate-500">{{ $label }}</p>
                            @if ($account->connection_status === \App\Models\EmailConnection::STATUS_ACTIVE)
                                <p class="mt-1 text-sm"><span class="font-medium text-emerald-700">Connected</span>@if ($account->last_tested_at) <span class="text-slate-500">· last checked {{ $account->last_tested_at->diffForHumans() }}</span>@endif</p>
                            @elseif ($account->connection_status === \App\Models\EmailConnection::STATUS_REAUTH_REQUIRED)
                                <p class="mt-1 text-sm font-medium text-amber-700">Needs reconnecting. Emails will not send until you reconnect.</p>
                            @else
                                <p class="mt-1 text-sm text-slate-500">Disconnected</p>
                            @endif
                            @if ($account->status_message && $account->connection_status !== \App\Models\EmailConnection::STATUS_ACTIVE)
                                <p class="mt-1 text-sm text-slate-500">{{ $account->status_message }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2 sm:shrink-0 sm:justify-end">
                            @if ($account->connection_status !== \App\Models\EmailConnection::STATUS_DISCONNECTED || $account->provider !== \App\Enums\EmailProvider::Smtp)
                                @if ($account->provider === \App\Enums\EmailProvider::Smtp)
                                    <button type="button" wire:click="openSmtpForm" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50">Reconnect</button>
                                @else
                                    <a href="{{ route('settings.email.oauth.redirect', ['provider' => $account->provider->value]) }}" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50">Reconnect</a>
                                @endif
                            @endif
                            @if ($account->connection_status === \App\Models\EmailConnection::STATUS_ACTIVE && ! $account->is_default)
                                <button type="button" wire:click="setDefault({{ $account->id }})" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50">Make default</button>
                            @endif
                            @if ($account->connection_status !== \App\Models\EmailConnection::STATUS_DISCONNECTED)
                                <button type="button" wire:click="beginDisconnect({{ $account->id }})" class="rounded-lg px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Disconnect</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($disconnectingId !== null && $this->accountConnections->firstWhere('id', $disconnectingId))
            @php
                $target = $this->accountConnections->firstWhere('id', $disconnectingId);
            @endphp
            <div role="alertdialog" aria-modal="true" aria-labelledby="disconnect-title" aria-describedby="disconnect-description" class="rounded-xl border border-red-200 bg-red-50/60 p-5">
                <h3 id="disconnect-title" class="text-base font-semibold text-slate-900">Disconnect {{ $target->sender_email }}?</h3>
                <p id="disconnect-description" class="mt-1 text-sm text-slate-700">
                    @if ($target->is_default)
                        This is your default sender, so automated quotes and follow-ups will stop until you choose another sender.
                    @else
                        Emails will no longer be sent from this address. You can reconnect it at any time.
                    @endif
                </p>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="cancelDisconnect" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50">Keep connected</button>
                    <button type="button" wire:click="confirmDisconnect" wire:loading.attr="disabled" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-red-500">Disconnect</button>
                </div>
            </div>
        @endif
    </section>

    {{-- Section 1: current sending email(s) --}}
    <section aria-labelledby="connections-heading" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="connections-heading" class="text-sm font-semibold text-slate-500">Your business domain</h2>

            @if ($this->domainConnections->isNotEmpty() && ! $showForm)
                <button type="button" wire:click="create" class="inline-flex items-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">
                    Add Business Email
                </button>
            @endif
        </div>

        @if ($this->domainConnections->isEmpty())
            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-10 text-center">
                <h3 class="text-base font-semibold text-slate-900">Connect your business email</h3>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">
                    Add your business domain and sender address so QuoteFollow can eventually send automated follow-ups from your own email.
                </p>

                @unless ($showForm)
                    <button type="button" wire:click="create" class="mt-6 inline-flex items-center rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">
                        Add Business Email
                    </button>
                @endunless
            </div>
        @else
            <ul role="list" class="space-y-4">
                @foreach ($this->domainConnections as $connection)
                    @php
                        $states = $this->actionStates[$connection->id];
                        $failed = $connection->verification_status === \App\Enums\EmailVerificationStatus::Failed;
                        $registered = $connection->isRegisteredWithProvider();
                        $showingRecords = $registered && $showingDnsRecordsFor === $connection->id;
                    @endphp
                    <li wire:key="connection-{{ $connection->id }}" class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                {{-- What this sender is: name, address, domain and whether it can be used. --}}
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="min-w-0 break-words text-base font-semibold text-slate-900">{{ $connection->sender_name }}</h3>
                                        @if ($connection->is_default)
                                            <span class="inline-flex items-center gap-1 rounded-lg bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-600/20 ring-inset">
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.8l-5.2 2.8 1-5.8L1.5 7.7l5.9-.9L10 1.5z"/></svg>
                                                Default sender
                                            </span>
                                        @endif
                                        <x-email-verification-badge :status="$connection->verification_status" />
                                    </div>
                                    <p class="mt-1 break-all text-sm text-slate-800">{{ $connection->sender_email }}</p>
                                    <p class="mt-0.5 text-sm text-slate-500">Domain: <span class="break-all font-medium text-slate-700">{{ $connection->domain }}</span></p>
                                </div>

                                {{-- What you can do: Edit stays visible, the rest is one tap away. --}}
                                <div class="flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end">
                                    @if ($connection->isVerified())
                                        <button type="button"
                                                wire:click="openTestEmail({{ $connection->id }})"
                                                @disabled(! $states['test']['allowed'])
                                                @unless ($states['test']['allowed']) aria-describedby="test-reason-{{ $connection->id }}" @endunless
                                                class="inline-flex flex-1 items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none">
                                            Send Test Email
                                        </button>
                                    @endif
                                    <button type="button" wire:click="edit({{ $connection->id }})" class="inline-flex flex-1 items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 sm:flex-none">
                                        Edit<span class="sr-only"> {{ $connection->sender_email }}</span>
                                    </button>

                                    <x-action-menu :label="'More actions for '.$connection->sender_email">
                                        @unless ($connection->isVerified())
                                            <x-action-menu-item :item-id="'menu-test-'.$connection->id" :disabled="true" :reason="$states['test']['reason']">Send Test Email</x-action-menu-item>
                                        @endunless
                                        @unless ($connection->is_default)
                                            <x-action-menu-item :item-id="'menu-default-'.$connection->id" wire:click="setDefault({{ $connection->id }})" :disabled="! $states['default']['allowed']" :reason="$states['default']['reason']">Set as Default</x-action-menu-item>
                                        @endunless
                                        <x-action-menu-item :item-id="'menu-delete-'.$connection->id" wire:click="confirmDelete({{ $connection->id }})" :disabled="! $states['delete']['allowed']" :reason="$states['delete']['reason']" :danger="true">Delete</x-action-menu-item>
                                    </x-action-menu>
                                </div>
                            </div>

                            @if ($connection->isVerified() && ! $states['test']['allowed'])
                                <p id="test-reason-{{ $connection->id }}" class="mt-3 text-sm text-slate-500">{{ $states['test']['reason'] }}</p>
                            @endif
                        </div>

                        @if ($connection->isVerified() && $testEmailConnectionId === $connection->id && $states['test']['allowed'])
                            <form wire:submit="sendTestEmail" class="border-t border-slate-100 bg-slate-50 p-5" novalidate>
                                <label for="testRecipient" class="block text-sm font-medium text-slate-900">Test Email Address</label>
                                <p id="testRecipient-hint" class="text-sm text-slate-500">
                                    We'll send a short test message from {{ $connection->sender_name }} &lt;<span class="break-all">{{ $connection->sender_email }}</span>&gt;.
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
                                                   'block w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2',
                                                   'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has('testRecipient'),
                                                   'border-slate-300 focus:border-violet-500 focus:ring-violet-500' => ! $errors->has('testRecipient'),
                                               ])>
                                        @error('testRecipient')
                                            <p id="testRecipient-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <button type="submit" wire:loading.attr="disabled" wire:target="sendTestEmail" class="inline-flex items-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                                            <span wire:loading.remove wire:target="sendTestEmail">Send Test Email</span>
                                            <span wire:loading wire:target="sendTestEmail" role="status">Sending…</span>
                                        </button>
                                        <button type="button" wire:click="cancelTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        @endif

                        @unless ($connection->isVerified())
                            {{-- Guided verification: what is wrong, the next step, and the one button that does it. --}}
                            <div class="border-t border-slate-100 bg-slate-50 p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 text-sm">
                                        <p class="font-semibold text-slate-900">
                                            @if ($failed)
                                                Your domain could not be verified.
                                            @elseif ($registered)
                                                Waiting for your DNS records to be detected.
                                            @else
                                                Your domain has not been verified yet.
                                            @endif
                                        </p>
                                        @if ($failed && $connection->verification_error)
                                            <p class="mt-1 text-red-700" role="alert">{{ $connection->verification_error }}</p>
                                            <p class="mt-1 text-slate-500">Check the domain spelling and your DNS records, then try again. If it keeps failing, contact support.</p>
                                        @elseif (! $registered)
                                            <p class="mt-1 text-slate-500">Verify your domain to prove you own it. We'll show the DNS records to add at your DNS provider.</p>
                                        @else
                                            <ol class="mt-2 list-decimal space-y-1 pl-5 text-slate-500">
                                                <li>Add the DNS records below at your DNS provider.</li>
                                                <li>Wait for DNS to update. This can take some time.</li>
                                                <li>Select <span class="font-medium text-slate-900">Check Verification</span>. Your domain only shows as verified once we confirm it.</li>
                                            </ol>
                                        @endif
                                        <p id="default-hint-{{ $connection->id }}" class="mt-2 text-slate-500">
                                            Only verified emails can send email or become the default sender.
                                        </p>
                                        @unless ($this->verificationConfigured)
                                            <p class="mt-2 rounded-lg bg-amber-50 p-2 text-amber-800">Domain verification isn't connected on this server yet. Ask your QuoteFlow administrator to add the Postmark account token.</p>
                                        @endunless
                                    </div>

                                    <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                        @if ($registered)
                                            <button type="button"
                                                    wire:click="toggleDnsRecords({{ $connection->id }})"
                                                    aria-expanded="{{ $showingRecords ? 'true' : 'false' }}"
                                                    aria-controls="dns-records-{{ $connection->id }}"
                                                    class="inline-flex flex-1 items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 sm:flex-none">
                                                {{ $showingRecords ? 'Hide DNS Records' : 'View DNS Records' }}
                                            </button>
                                            <button type="button"
                                                    wire:click="checkVerification({{ $connection->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="checkVerification({{ $connection->id }})"
                                                    @disabled(! $this->verificationConfigured)
                                                    class="inline-flex flex-1 items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 sm:flex-none">
                                                <span wire:loading.remove wire:target="checkVerification({{ $connection->id }})">Check Verification</span>
                                                <span wire:loading wire:target="checkVerification({{ $connection->id }})" role="status">Checking…</span>
                                            </button>
                                        @else
                                            <button type="button"
                                                    wire:click="startVerification({{ $connection->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="startVerification({{ $connection->id }})"
                                                    @disabled(! $this->verificationConfigured)
                                                    class="inline-flex w-full items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                                                <span wire:loading.remove wire:target="startVerification({{ $connection->id }})">{{ $failed ? 'Try Again' : 'Verify Domain' }}</span>
                                                <span wire:loading wire:target="startVerification({{ $connection->id }})" role="status">Connecting…</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                @if ($showingRecords)
                                    @php($records = $connection->dnsRecords())
                                    @php($hasPriority = collect($records)->contains(fn ($record) => $record->priority !== null))

                                    <div id="dns-records-{{ $connection->id }}" class="mt-4 border-t border-slate-200 pt-4">
                                        <h3 class="text-sm font-semibold text-slate-900">DNS records</h3>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Add these DNS records at the DNS provider for <span class="font-medium text-slate-900">{{ $connection->domain }}</span>.
                                            DNS changes can take some time to propagate.
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Some DNS providers add your domain to the name automatically. If yours does, enter only the part before ".{{ $connection->domain }}".
                                        </p>

                                        @if ($records === [])
                                            <p class="mt-3 text-sm text-slate-700">
                                                We couldn't load the DNS records.
                                                <button type="button" wire:click="startVerification({{ $connection->id }})" class="font-medium text-violet-700 underline-offset-2 hover:underline">Try again</button>
                                            </p>
                                        @else
                                            {{-- Wide screens: table --}}
                                            <div class="mt-3 hidden overflow-hidden rounded-lg ring-1 ring-slate-200 sm:block">
                                                <table class="w-full table-fixed text-left text-sm">
                                                    <thead class="bg-white text-xs text-slate-500">
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
                                                    <tbody class="divide-y divide-slate-200 bg-white">
                                                        @foreach ($records as $record)
                                                            <tr class="align-top">
                                                                <td class="px-3 py-3 font-mono text-slate-900">
                                                                    {{ $record->type }}
                                                                    <span class="block font-sans text-xs text-slate-500">{{ $record->purpose }}</span>
                                                                </td>
                                                                <td class="px-3 py-3">
                                                                    <div class="flex items-start gap-2">
                                                                        <span class="min-w-0 font-mono break-all text-slate-900">{{ $record->name }}</span>
                                                                        <x-copy-button :value="$record->name" :label="'Copy '.$record->purpose.' name'" />
                                                                    </div>
                                                                </td>
                                                                <td class="px-3 py-3">
                                                                    <div class="flex items-start gap-2">
                                                                        <span class="line-clamp-3 min-w-0 font-mono break-all text-slate-900" title="{{ $record->value }}">{{ $record->value }}</span>
                                                                        <x-copy-button :value="$record->value" :label="'Copy '.$record->purpose.' value'" />
                                                                    </div>
                                                                </td>
                                                                @if ($hasPriority)
                                                                    <td class="px-3 py-3 text-slate-900">{{ $record->priority ?? '—' }}</td>
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
                                                    <li class="rounded-lg bg-white p-3 text-sm ring-1 ring-slate-200">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="font-mono font-medium text-slate-900">{{ $record->type }} <span class="font-sans font-normal text-slate-500">· {{ $record->purpose }}</span></span>
                                                            <x-dns-record-status :verified="$record->verified" />
                                                        </div>
                                                        <dl class="mt-2 space-y-2">
                                                            <div>
                                                                <dt class="text-xs text-slate-500">Name</dt>
                                                                <dd class="flex items-start justify-between gap-2"><span class="min-w-0 font-mono break-all">{{ $record->name }}</span><x-copy-button :value="$record->name" :label="'Copy '.$record->purpose.' name'" /></dd>
                                                            </div>
                                                            <div>
                                                                <dt class="text-xs text-slate-500">Value</dt>
                                                                <dd class="flex items-start justify-between gap-2"><span class="line-clamp-4 min-w-0 font-mono break-all">{{ $record->value }}</span><x-copy-button :value="$record->value" :label="'Copy '.$record->purpose.' value'" /></dd>
                                                            </div>
                                                            @if ($record->priority !== null)
                                                                <div>
                                                                    <dt class="text-xs text-slate-500">Priority</dt>
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
        <section aria-labelledby="form-heading" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 id="form-heading" class="text-base font-semibold text-slate-900">
                {{ $editingConnectionId ? 'Edit business email' : 'Add business email' }}
            </h2>
            @if ($editingConnectionId)
                <p class="mt-1 text-sm text-slate-500">Changing the domain or sender email resets its verification status.</p>
            @endif

            <form wire:submit="save" class="mt-6 space-y-5" novalidate>
                @foreach ([
                    ['domain', 'Business domain', 'example.com', 'text', 'off'],
                    ['senderName', 'Sender name', 'Dallas Cooling', 'text', 'organization'],
                    ['senderEmail', 'Sender email', 'sales@example.com', 'email', 'email'],
                ] as [$field, $label, $placeholder, $type, $autocomplete])
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium text-slate-900">{{ $label }}</label>
                        <input id="{{ $field }}"
                               type="{{ $type }}"
                               wire:model="{{ $field }}"
                               placeholder="{{ $placeholder }}"
                               autocomplete="{{ $autocomplete }}"
                               @if ($loop->first) autofocus @endif
                               @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                               @class([
                                   'mt-1 block w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 sm:max-w-md',
                                   'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has($field),
                                   'border-slate-300 focus:border-violet-500 focus:ring-violet-500' => ! $errors->has($field),
                               ])>
                        @error($field)
                            <p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="inline-flex items-center rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="save">Save</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                    <button type="button" wire:click="cancel" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeletionId && ($pendingDeletion = $this->domainConnections->firstWhere('id', $confirmingDeletionId)))
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-gray-900/50 p-4 sm:items-center"
             x-data
             x-trap.noscroll="true"
             @keydown.escape.window="$wire.cancelDelete()">
            <div role="alertdialog" aria-modal="true" aria-labelledby="delete-title" aria-describedby="delete-description"
                 class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 id="delete-title" class="text-base font-semibold text-slate-900">Remove email connection</h2>
                <div id="delete-description" class="mt-2 space-y-2 text-sm text-slate-500">
                    <p>Are you sure you want to remove this email connection?</p>
                    <p class="font-medium text-slate-900">{{ $pendingDeletion->sender_email }}</p>
                    @if ($pendingDeletion->is_default)
                        <p class="rounded-lg bg-amber-50 p-3 text-amber-800">
                            This is your default sender. Until you set another verified connection as default, QuoteFollow will have no default sending email.
                        </p>
                    @endif
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="cancelDelete" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                        Cancel
                    </button>
                    <button type="button" wire:click="delete" wire:loading.attr="disabled" wire:target="delete" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-60">
                        Remove
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
</x-settings.shell>

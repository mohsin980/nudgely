@php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
@php($badge = 'grid size-12 shrink-0 place-items-center rounded-2xl')
@php($field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($label = 'block text-[15px] font-semibold text-slate-800')
@php($primaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50')
@php($secondaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50 disabled:opacity-50')
@php($connected = $connection?->isActive() ?? false)
<x-settings.shell title="WhatsApp" description="Follow up with customers on WhatsApp, email, or both.">
    @include('livewire.settings.partials.status')

    {{-- 1. How the business follows up --}}
    <form wire:submit="saveChannels" class="{{ $card }}" aria-labelledby="channels-heading">
        <div class="flex items-center gap-4">
            <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="arrows-right-left" class="size-6" /></span>
            <div>
                <h2 id="channels-heading" class="text-lg font-bold text-slate-900">How do you follow up?</h2>
                <p class="text-sm text-slate-500">Used for customers who haven't picked a channel. You can set a channel per customer too.</p>
            </div>
        </div>
        <div class="mt-5 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Follow-up channels">
            @foreach (['email' => ['envelope', 'Email', 'From your business email.'], 'whatsapp' => ['chat', 'WhatsApp', 'From your WhatsApp number.'], 'both' => ['arrows-right-left', 'Both', 'Each customer on their channel.']] as $value => [$icon, $optionLabel, $help])
                <label @class(['flex cursor-pointer items-start gap-3 rounded-2xl p-4 ring-1 transition', 'bg-violet-50/70 ring-violet-300' => $channels === $value, 'bg-white ring-slate-200 hover:ring-violet-200' => $channels !== $value])>
                    <input type="radio" wire:model.live="channels" value="{{ $value }}" class="mt-1 size-4 border-slate-300 text-violet-600 focus:ring-violet-500" data-channel-option="{{ $value }}">
                    <span>
                        <span class="flex items-center gap-2 text-[15px] font-bold text-slate-900"><x-ui-icon :name="$icon" class="size-5 text-violet-600" />{{ $optionLabel }}</span>
                        <span class="mt-0.5 block text-sm text-slate-500">{{ $help }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @if ($channels === 'both')
            <div class="mt-4 sm:max-w-xs">
                <label for="primary-channel" class="{{ $label }}">Main channel</label>
                <select id="primary-channel" wire:model="primary" class="{{ $field }}">
                    <option value="email">Email</option>
                    <option value="whatsapp">WhatsApp</option>
                </select>
                <p class="mt-1 text-xs text-slate-500">For customers who haven't picked one. If WhatsApp isn't possible for a customer (no consent or number), email is used.</p>
            </div>
        @endif
        @error('channels') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        <div class="mt-5 flex justify-end"><button type="submit" class="{{ $primaryButton }}">Save</button></div>
    </form>

    {{-- 2. Connection --}}
    <section class="{{ $card }}" aria-labelledby="connect-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-emerald-100 text-emerald-600"><x-ui-icon name="chat" class="size-6" /></span>
                <div>
                    <h2 id="connect-heading" class="text-lg font-bold text-slate-900">Your WhatsApp number</h2>
                    <p class="text-sm text-slate-500">Your own WhatsApp Business number on Meta. Meta bills you directly for messages.</p>
                </div>
            </div>
            @if ($connection)
                <span @class(['inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $connected, 'bg-rose-50 text-rose-700 ring-rose-600/20' => $connection->status === 'failed', 'bg-amber-50 text-amber-800 ring-amber-600/20' => $connection->status === 'pending']) data-whatsapp-status="{{ $connection->status }}">
                    <span class="size-2 rounded-full bg-current"></span>{{ $connected ? 'Connected' : ($connection->status === 'failed' ? 'Not working' : 'Checking') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600" data-whatsapp-status="none"><span class="size-2 rounded-full bg-slate-400"></span>Not connected</span>
            @endif
        </div>

        @if ($connected)
            <dl class="mt-5 grid gap-3 rounded-xl bg-emerald-50/50 p-4 text-sm ring-1 ring-emerald-100 sm:grid-cols-3">
                <div><dt class="text-slate-500">Number</dt><dd class="font-semibold text-slate-900">{{ $connection->display_phone_number ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Business name on WhatsApp</dt><dd class="font-semibold text-slate-900">{{ $connection->verified_name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Checked</dt><dd class="font-semibold text-slate-900">{{ $connection->verified_at ? $organization->formatDateTime($connection->verified_at) : '—' }}</dd></div>
            </dl>
        @elseif ($connection?->last_error)
            <p role="alert" class="mt-5 rounded-xl bg-rose-50 p-3 text-sm text-rose-800" data-whatsapp-error><span class="font-semibold">Meta says:</span> {{ $connection->last_error }}</p>
        @endif

        @if ($canManage)
            <form wire:submit="connect" class="mt-5 grid gap-4 sm:grid-cols-2" autocomplete="off">
                <div><label for="wa-phone-id" class="{{ $label }}">Phone number ID</label><input id="wa-phone-id" type="text" inputmode="numeric" wire:model="phoneNumberId" placeholder="e.g. 106540352242922" class="{{ $field }}">@error('phoneNumberId') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="wa-waba-id" class="{{ $label }}">WhatsApp Business account ID</label><input id="wa-waba-id" type="text" inputmode="numeric" wire:model="businessAccountId" placeholder="e.g. 102290129340398" class="{{ $field }}">@error('businessAccountId') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="wa-token" class="{{ $label }}">Access token</label><input id="wa-token" type="password" wire:model="accessToken" placeholder="{{ $connection ? 'Saved. Leave empty to keep it' : 'Permanent access token' }}" class="{{ $field }}">@error('accessToken') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="wa-secret" class="{{ $label }}">App secret</label><input id="wa-secret" type="password" wire:model="appSecret" placeholder="{{ $connection ? 'Saved. Leave empty to keep it' : 'From App settings → Basic' }}" class="{{ $field }}">@error('appSecret') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="connect" class="{{ $primaryButton }}"><span wire:loading.remove wire:target="connect">{{ $connection ? 'Save and check again' : 'Connect WhatsApp' }}</span><span wire:loading wire:target="connect">Checking with Meta…</span></button>
                    @if ($connection)
                        <button type="button" wire:click="recheck" class="{{ $secondaryButton }}">Check connection</button>
                        <button type="button" wire:click="disconnect" wire:confirm="Disconnect WhatsApp? Follow-ups will go by email until you connect it again." class="rounded-xl px-3 py-2.5 text-sm font-semibold text-rose-600 hover:bg-rose-50">Disconnect</button>
                    @endif
                </div>
            </form>
        @endif
    </section>

    @if ($connection)
        {{-- 3. Webhook for replies --}}
        <section class="{{ $card }}" aria-labelledby="webhook-heading">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-sky-100 text-sky-600"><x-ui-icon name="inbox" class="size-6" /></span>
                <div>
                    <h2 id="webhook-heading" class="text-lg font-bold text-slate-900">Receive replies</h2>
                    <p class="text-sm text-slate-500">Paste these into your Meta app (WhatsApp → Configuration → Webhook), then subscribe to <strong class="font-semibold text-slate-700">messages</strong>.</p>
                </div>
            </div>
            <dl class="mt-5 space-y-3 text-sm">
                @foreach (['Callback URL' => $webhookUrl, 'Verify token' => $connection->webhook_verify_token] as $term => $value)
                    <div>
                        <dt class="font-semibold text-slate-800">{{ $term }}</dt>
                        <dd class="mt-1 flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 ring-1 ring-slate-200"><code class="min-w-0 flex-1 truncate text-slate-800">{{ $value }}</code><x-copy-button :value="$value" :label="'Copy '.$term" /></dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($connected && $canManage)
        {{-- Follow-up template --}}
        <form wire:submit="saveTemplate" class="{{ $card }}" aria-labelledby="template-heading">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="document" class="size-6" /></span>
                <div>
                    <h2 id="template-heading" class="text-lg font-bold text-slate-900">Follow-up template</h2>
                    <p class="text-sm text-slate-500">WhatsApp only lets you write freely within 24 hours of the customer's last message. After that, follow-ups use this template, which Meta must approve first.</p>
                </div>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                <div><label for="wa-template" class="{{ $label }}">Template name in Meta</label><input id="wa-template" type="text" wire:model="followUpTemplate" placeholder="e.g. quote_follow_up" class="{{ $field }}">@error('followUpTemplate') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="wa-template-language" class="{{ $label }}">Language code</label><input id="wa-template-language" type="text" wire:model="templateLanguage" placeholder="en" class="{{ $field }}">@error('templateLanguage') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            </div>
            <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600 ring-1 ring-slate-200">Example to create in WhatsApp Manager → Message templates (category <strong class="font-semibold text-slate-800">Utility</strong>): <em>"Hi @{{1}}, just checking in about your quote. Reply here if you have any questions."</em> QuoteFlow fills <code>@{{1}}</code> with the customer's first name. Without a template, these follow-ups go by email when the customer has one.</p>
            <div class="mt-5 flex justify-end"><button type="submit" class="{{ $primaryButton }}">Save template</button></div>
        </form>

        {{-- 4. Test --}}
        <form wire:submit="sendTest" class="{{ $card }}" aria-labelledby="test-heading">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-amber-100 text-amber-600"><x-ui-icon name="paper-airplane" class="size-6" /></span>
                <div>
                    <h2 id="test-heading" class="text-lg font-bold text-slate-900">Send a test message</h2>
                    <p class="text-sm text-slate-500">Sends Meta's "hello_world" template. While your Meta app is in development, the number must be added as a test recipient in Meta.</p>
                </div>
            </div>
            <div class="mt-5 flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1 sm:max-w-sm"><label for="wa-test-to" class="{{ $label }}">Your mobile number</label><input id="wa-test-to" type="tel" wire:model="testTo" placeholder="+92 300 1234567" class="{{ $field }}"></div>
                <button type="submit" wire:loading.attr="disabled" wire:target="sendTest" class="{{ $primaryButton }}">Send test</button>
            </div>
            @error('testTo') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </form>
    @endif

    {{-- Setup guide --}}
    <section class="{{ $card }}" aria-labelledby="guide-heading" x-data="{ open: {{ $connected ? 'false' : 'true' }} }">
        <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 text-left" :aria-expanded="open">
            <span class="flex items-center gap-4">
                <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="list" class="size-6" /></span>
                <span>
                    <span id="guide-heading" class="block text-lg font-bold text-slate-900">How to connect (about 20 minutes, once)</span>
                    <span class="block text-sm text-slate-500">Everything here is free to set up. Meta charges per message once you start sending.</span>
                </span>
            </span>
            <x-ui-icon name="chevron-down" class="size-5 text-slate-500 transition" ::class="open && 'rotate-180'" />
        </button>
        <ol x-show="open" x-cloak class="mt-5 space-y-4 text-[15px] text-slate-700" data-section="whatsapp-guide">
            @foreach ([
                ['Create a Meta Business account', 'Go to business.facebook.com and create an account for your business (skip if you have one).'],
                ['Create an app with WhatsApp', 'At developers.facebook.com → My Apps → Create app → choose "Business", then add the WhatsApp product. Meta gives you a test number to start; add your own number under WhatsApp → API Setup when ready.'],
                ['Copy the IDs and a permanent token', 'In WhatsApp → API Setup copy the Phone number ID and WhatsApp Business account ID. For a token that doesn\'t expire, create a System User in Business Settings with the "whatsapp_business_messaging" permission and generate its token. Copy the App secret from App settings → Basic. Paste all four above and press Connect.'],
                ['Add the webhook', 'Once connected, copy the Callback URL and Verify token shown above into WhatsApp → Configuration → Webhook, press Verify and save, then subscribe to "messages".'],
                ['Add a payment method in Meta', 'In WhatsApp Manager → Payment settings. Meta bills you directly; QuoteFlow adds nothing on top.'],
            ] as $index => [$stepTitle, $stepText])
                <li class="flex gap-3.5">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-violet-600 text-sm font-bold text-white">{{ $index + 1 }}</span>
                    <div><p class="font-semibold text-slate-900">{{ $stepTitle }}</p><p class="mt-0.5 text-sm text-slate-600">{{ $stepText }}</p></div>
                </li>
            @endforeach
        </ol>
        @include('livewire.settings.partials.setup-help', ['topic' => 'WhatsApp'])
    </section>
</x-settings.shell>

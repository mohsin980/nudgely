<x-settings.shell title="WhatsApp" description="Connect your own WhatsApp Business number to send and receive customer messages in QuoteFlow.">
    @if (session('status'))
        <p role="status" class="rounded-lg bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</p>
    @endif

    <section aria-labelledby="whatsapp-heading" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h2 id="whatsapp-heading" class="text-base font-semibold text-slate-900">Connection</h2>

        @if ($connection)
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500">Number</dt>
                    <dd class="font-medium text-slate-900">{{ $connection->display_phone_number }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Business name</dt>
                    <dd class="font-medium text-slate-900">{{ $connection->verified_name ?? 'Not shown by Meta' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Connected</dt>
                    <dd class="font-medium text-slate-900">{{ $connection->connected_at->format('j M Y') }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-slate-600">Customer messages to this number appear in Messages. Replies after 24 hours use an approved template.</p>
        @elseif ($signupReady)
            <p class="mt-2 text-sm text-slate-600">Connect your WhatsApp Business number. Meta asks you to log in, choose your business and confirm the number. QuoteFlow does not see your Meta password.</p>

            <form method="POST" action="{{ route('settings.whatsapp.connect') }}" id="whatsapp-connect-form" class="mt-4">
                @csrf
                <input type="hidden" name="code" id="whatsapp-code">
                <input type="hidden" name="waba_id" id="whatsapp-waba-id">
                <input type="hidden" name="phone_number_id" id="whatsapp-phone-number-id">
                <button type="button" id="whatsapp-connect" class="inline-flex items-center rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">
                    Connect WhatsApp
                </button>
            </form>
        @else
            <p class="mt-2 text-sm text-slate-600">WhatsApp is not available yet. Check back soon.</p>
        @endif
    </section>

    @if ($signupReady && ! $connection)
        <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
        <script>
            // Meta's Embedded Signup reports the number and business IDs in a message event, before the login callback.
            const whatsappSignup = {};

            window.addEventListener('message', (event) => {
                if (! event.origin.endsWith('facebook.com')) {
                    return;
                }

                try {
                    const data = JSON.parse(event.data);

                    if (data.type === 'WA_EMBEDDED_SIGNUP' && data.event === 'FINISH') {
                        whatsappSignup.wabaId = data.data.waba_id;
                        whatsappSignup.phoneNumberId = data.data.phone_number_id;
                    }
                } catch (error) {
                    // Other messages on the page are not ours.
                }
            });

            window.fbAsyncInit = () => {
                FB.init({ appId: @json($appId), autoLogAppEvents: true, xfbml: false, version: @json($graphVersion) });
            };

            document.getElementById('whatsapp-connect').addEventListener('click', () => {
                FB.login((response) => {
                    const code = response.authResponse?.code;

                    if (! code || ! whatsappSignup.wabaId || ! whatsappSignup.phoneNumberId) {
                        return;
                    }

                    document.getElementById('whatsapp-code').value = code;
                    document.getElementById('whatsapp-waba-id').value = whatsappSignup.wabaId;
                    document.getElementById('whatsapp-phone-number-id').value = whatsappSignup.phoneNumberId;
                    document.getElementById('whatsapp-connect-form').submit();
                }, {
                    config_id: @json($signupConfigId),
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: { setup: {}, sessionInfoVersion: '3' },
                });
            });
        </script>
    @endif
</x-settings.shell>

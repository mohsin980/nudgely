// Settings forms: warn before leaving with unsaved changes (closing the tab or navigating away).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('unsavedChanges', () => ({
        dirty: false,
        init() {
            const markDirty = () => { this.dirty = true; };
            this.$el.addEventListener('input', markDirty);
            this.$el.addEventListener('change', markDirty);
            window.addEventListener('settings-saved', () => { this.dirty = false; });
            window.addEventListener('beforeunload', (event) => {
                if (this.dirty && this.$el.isConnected) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            document.addEventListener('livewire:navigate', (event) => {
                if (this.dirty && this.$el.isConnected && ! window.confirm('You have unsaved changes. Leave this page?')) {
                    event.preventDefault();
                }
            });
        },
    }));
});

// Facebook's JavaScript SDK, loaded only when a Facebook button is pressed.
function loadFacebookSdk(config) {
    return new Promise((resolve, reject) => {
        if (window.FB) {
            resolve();
            return;
        }

        window.fbAsyncInit = () => {
            window.FB.init({ appId: config.appId, autoLogAppEvents: false, xfbml: false, version: config.version });
            resolve();
        };

        const script = document.createElement('script');
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.onerror = () => reject(new Error('sdk'));
        document.body.appendChild(script);
    });
}

// Reached by in-page navigation: reload once so the page's own security headers (which allow Facebook's popup
// and script) apply. Returns true when a reload was started.
function reloadForFacebookHeaders() {
    const entry = performance.getEntriesByType('navigation')[0];
    if (entry && new URL(entry.name).pathname !== window.location.pathname) {
        window.location.reload();
        return true;
    }

    return false;
}

// Settings → WhatsApp: one-click connect with Meta's Embedded Signup popup. Meta's popup reports the WhatsApp
// Business account and number it set up with postMessage; FB.login returns a one-time code. Both go to the
// server (completeEmbeddedSignup), which checks everything with Meta itself.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('whatsappEmbeddedSignup', (config) => ({
        busy: false,
        error: '',
        code: null,
        session: null,

        init() {
            if (reloadForFacebookHeaders()) {
                return;
            }

            window.addEventListener('message', (event) => this.receive(event));
        },

        receive(event) {
            if (! /^https:\/\/([a-z0-9-]+\.)?facebook\.com$/.test(event.origin)) {
                return;
            }

            let data;
            try {
                data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            } catch {
                return;
            }

            if (data?.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }

            if (data.event === 'FINISH' && data.data?.phone_number_id && data.data?.waba_id) {
                this.session = { phone: String(data.data.phone_number_id), account: String(data.data.waba_id) };
                this.finish();
            } else if (data.event === 'CANCEL') {
                this.error = data.data?.error_message || 'The Facebook window was closed before WhatsApp was connected.';
                this.busy = false;
            } else if (data.event === 'ERROR') {
                this.error = data.data?.error_message || 'Meta reported an error. Please try again.';
                this.busy = false;
            } else if (String(data.event).startsWith('FINISH')) {
                this.error = 'Meta finished without a WhatsApp number. Connect again and add or pick a phone number.';
                this.busy = false;
            }
        },

        loadSdk() {
            return loadFacebookSdk(config);
        },

        async start() {
            this.error = '';
            this.code = null;
            this.session = null;
            this.busy = true;

            try {
                await this.loadSdk();
            } catch {
                this.error = 'Facebook could not be loaded. Check your connection or ad blocker, then try again.';
                this.busy = false;
                return;
            }

            window.FB.login((response) => {
                if (response.authResponse?.code) {
                    this.code = response.authResponse.code;
                    this.finish();
                    // The number normally arrives with the code; if it never does, say so instead of waiting forever.
                    setTimeout(() => {
                        if (this.busy && ! this.session) {
                            this.error = 'Meta did not share a WhatsApp number. Connect again and finish every step.';
                            this.busy = false;
                        }
                    }, 8000);
                } else {
                    this.error ||= 'The Facebook window was closed before WhatsApp was connected.';
                    this.busy = false;
                }
            }, {
                config_id: config.configId,
                response_type: 'code',
                override_default_response_type: true,
                extras: { setup: {}, featureType: '', sessionInfoVersion: '3' },
            });
        },

        finish() {
            if (! this.code || ! this.session) {
                return;
            }

            const [code, session] = [this.code, this.session];
            this.code = null;
            this.$wire.completeEmbeddedSignup(code, session.phone, session.account).finally(() => { this.busy = false; });
        },
    }));
});

// Settings → Lead Ads: sign in with Facebook to list the Pages that run lead ads. Only the short-lived token goes
// to the server, which checks it with Meta and keeps the Page tokens to itself.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('metaLeadAdsLogin', (config) => ({
        busy: false,
        error: '',

        init() {
            reloadForFacebookHeaders();
        },

        async start() {
            this.error = '';
            this.busy = true;

            try {
                await loadFacebookSdk(config);
            } catch {
                this.error = 'Facebook could not be loaded. Check your connection or ad blocker, then try again.';
                this.busy = false;
                return;
            }

            const options = config.configId ? { config_id: config.configId } : { scope: config.scope, return_scopes: true };

            window.FB.login((response) => {
                const token = response.authResponse?.accessToken;

                if (! token) {
                    this.error = 'The Facebook window was closed before your Page was shared.';
                    this.busy = false;
                    return;
                }

                this.$wire.loadPages(token).finally(() => { this.busy = false; });
            }, options);
        },
    }));
});

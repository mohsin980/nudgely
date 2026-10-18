<?php

namespace App\Services\WhatsApp;

/**
 * Whether WhatsApp is switched on and fully configured. Nothing is shown or accepted until this is true,
 * so a half-configured install never offers a connect button that cannot work.
 */
class WhatsAppConfiguration
{
    public function enabled(): bool
    {
        return (bool) config('whatsapp.enabled');
    }

    public function signupReady(): bool
    {
        return $this->enabled()
            && filled(config('whatsapp.app_id'))
            && filled(config('whatsapp.app_secret'))
            && filled(config('whatsapp.signup_config_id'));
    }

    public function webhookReady(): bool
    {
        return $this->enabled() && filled(config('whatsapp.app_secret')) && filled(config('whatsapp.verify_token'));
    }
}

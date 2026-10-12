<?php

namespace App\Services\Email;

use App\Contracts\Email\EmailProviderInterface;
use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Providers\PostmarkEmailProvider;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves the transactional email provider implementation for a provider name.
 *
 * @method EmailProviderInterface driver(?string $driver = null)
 */
class EmailProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $provider = $this->config->get('email.provider');

        if (blank($provider)) {
            throw EmailProviderException::notConfigured('QUOTE_FLOW_EMAIL_PROVIDER is not configured.');
        }

        return $provider;
    }

    /**
     * Can emails be sent through the configured provider? Only whether credentials exist: nothing secret is returned.
     */
    public function sendingConfigured(): bool
    {
        return $this->credentialConfigured('server_token');
    }

    /**
     * Can domains be registered and verified with the configured provider?
     */
    public function domainVerificationConfigured(): bool
    {
        return $this->credentialConfigured('account_token');
    }

    private function credentialConfigured(string $key): bool
    {
        $provider = $this->config->get('email.provider');

        // A driver registered in code (extend()) brings its own setup, so there is no credential to look for.
        if (filled($provider) && isset($this->customCreators[$provider])) {
            return true;
        }

        return filled($provider) && filled($this->config->get("email.providers.{$provider}.{$key}"));
    }

    /**
     * The implementation for a connection's stored provider.
     */
    public function for(EmailProvider $provider): EmailProviderInterface
    {
        return $this->driver($provider->value);
    }

    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            throw EmailProviderException::notConfigured("Email provider [{$driver}] is not supported.");
        }
    }

    protected function createPostmarkDriver(): EmailProviderInterface
    {
        return new PostmarkEmailProvider($this->config->get('email.providers.postmark', []));
    }
}

<?php

namespace App\Services\Email\Inbound;

use App\Contracts\Email\InboundEmailProviderInterface;
use Illuminate\Support\Manager;

/**
 * Resolves the inbound webhook handler for a provider name (e.g. from the webhook URL).
 *
 * @method InboundEmailProviderInterface driver(?string $driver = null)
 */
class InboundEmailProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('email.provider', 'postmark');
    }

    public function supports(string $provider): bool
    {
        return method_exists($this, 'create'.ucfirst($provider).'Driver') || isset($this->customCreators[$provider]);
    }

    protected function createPostmarkDriver(): InboundEmailProviderInterface
    {
        return new PostmarkInboundWebhook($this->config->get('email.providers.postmark', []));
    }
}

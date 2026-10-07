<?php

namespace App\Billing;

/**
 * A finished checkout as the provider reports it: who it was for and the subscription it created.
 */
final class CompletedCheckout
{
    public function __construct(
        public readonly string $sessionId,
        public readonly ?string $customerId,
        public readonly ?int $organizationId,
        public readonly ProviderSubscription $subscription,
    ) {}
}

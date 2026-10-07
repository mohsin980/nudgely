<?php

namespace App\Billing;

/**
 * A hosted checkout page at the provider: the customer pays there, never in QuoteFollow.
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}

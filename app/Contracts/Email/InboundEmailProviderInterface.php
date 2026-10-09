<?php

namespace App\Contracts\Email;

use App\Exceptions\Email\InvalidInboundEmailException;
use App\Services\Email\Data\InboundEmail;
use Illuminate\Http\Request;

/**
 * A provider's inbound email webhook: authenticity check, payload sanitizing and normalization.
 */
interface InboundEmailProviderInterface
{
    /**
     * Whether the request really comes from the provider. Must fail closed when not configured.
     */
    public function authenticate(Request $request): bool;

    /**
     * Normalize a provider payload into an InboundEmail.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidInboundEmailException
     */
    public function parse(array $payload): InboundEmail;

    /**
     * The payload with anything that must not be stored removed (e.g. attachment contents).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $payload): array;
}

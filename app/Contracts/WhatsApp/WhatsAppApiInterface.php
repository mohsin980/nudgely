<?php

namespace App\Contracts\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Models\WhatsAppConnection;

/**
 * The calls QuoteFlow makes to Meta's WhatsApp Cloud API. Implementations throw WhatsAppApiException
 * for every failure, so callers never handle raw HTTP responses.
 */
interface WhatsAppApiInterface
{
    /**
     * @return string Meta's message ID (wamid) for the accepted message.
     *
     * @throws WhatsAppApiException
     */
    public function sendText(WhatsAppConnection $connection, string $to, string $body): string;

    /**
     * @param  list<string>  $parameters  Values for the template's body placeholders, in order.
     * @return string Meta's message ID (wamid) for the accepted message.
     *
     * @throws WhatsAppApiException
     */
    public function sendTemplate(WhatsAppConnection $connection, string $to, string $name, string $language, array $parameters): string;

    /**
     * Exchanges the Embedded Signup code for a business access token.
     *
     * @throws WhatsAppApiException
     */
    public function exchangeCode(string $code): string;

    /**
     * The number's public details, shown to the business after it connects.
     *
     * @return array{display_phone_number: string, verified_name: ?string}
     *
     * @throws WhatsAppApiException
     */
    public function phoneNumber(string $phoneNumberId, string $accessToken): array;

    /**
     * Subscribes the platform's app to the business account, so Meta sends its webhooks for that number.
     *
     * @throws WhatsAppApiException
     */
    public function subscribeApp(string $wabaId, string $accessToken): void;
}

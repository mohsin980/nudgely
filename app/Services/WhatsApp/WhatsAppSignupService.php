<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppApiInterface;
use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Exceptions\WhatsApp\WhatsAppException;
use App\Models\Organization;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Log;

/**
 * Connects a business's own WhatsApp Business number, after the business finishes Meta's Embedded Signup.
 *
 * The signup code is exchanged for the business's token on the server. The token is stored encrypted on
 * the connection and never returned to the browser.
 */
class WhatsAppSignupService
{
    public function __construct(private readonly WhatsAppApiInterface $api) {}

    /**
     * @throws WhatsAppException
     */
    public function connect(Organization $organization, string $code, string $wabaId, string $phoneNumberId): WhatsAppConnection
    {
        $numberInUse = WhatsAppConnection::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('organization_id', '!=', $organization->id)
            ->exists();

        if ($numberInUse) {
            throw WhatsAppException::numberInUse();
        }

        try {
            $token = $this->api->exchangeCode($code);
            $this->api->subscribeApp($wabaId, $token);
            $number = $this->api->phoneNumber($phoneNumberId, $token);
        } catch (WhatsAppApiException $e) {
            Log::warning('whatsapp.signup_failed', ['event' => 'whatsapp.signup_failed', 'organization_id' => $organization->id, 'reason' => $e->getMessage()]);

            throw WhatsAppException::signupFailed();
        }

        $connection = WhatsAppConnection::query()->where('organization_id', $organization->id)->first() ?? new WhatsAppConnection;
        $connection->forceFill([
            'organization_id' => $organization->id,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'display_phone_number' => $number['display_phone_number'],
            'verified_name' => $number['verified_name'],
            'access_token' => $token,
            'status' => WhatsAppConnection::STATUS_ACTIVE,
            'connected_at' => now(),
        ])->save();

        Log::info('whatsapp.connected', ['event' => 'whatsapp.connected', 'organization_id' => $organization->id]);

        return $connection;
    }
}

<?php

namespace App\Services\Email\OAuth;

use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use App\Enums\Team\Permission;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Completes a Gmail or Microsoft connection after the business has approved access. The refresh token is stored
 * encrypted on the connection; the sender address is the account's own verified address.
 */
class OAuthConnectionService
{
    public function __construct(private readonly OAuthMailClient $client) {}

    /**
     * @throws ValidationException|EmailProviderException
     */
    public function connect(User $actor, Organization $organization, EmailProvider $provider, string $code, string $verifier, string $redirectUri): EmailConnection
    {
        if ($actor->organization_id !== $organization->id || ! $actor->hasPermission(Permission::ManageEmail)) {
            throw ValidationException::withMessages(['email' => 'Only the business owner can change email settings.']);
        }

        $tokens = $this->client->exchangeCode($provider, $code, $verifier, $redirectUri);

        if ($tokens['refresh_token'] === null) {
            throw EmailProviderException::rejected('The provider did not grant offline access.');
        }

        $identity = $this->client->identity($provider, $tokens['access_token']);

        return DB::transaction(function () use ($organization, $provider, $tokens, $identity) {
            $connection = EmailConnection::query()
                ->where('organization_id', $organization->id)
                ->where('provider', $provider)
                ->where('sender_email', $identity['email'])
                ->lockForUpdate()
                ->first() ?? new EmailConnection;

            $connection->forceFill([
                'organization_id' => $organization->id,
                'provider' => $provider,
                'domain' => explode('@', $identity['email'])[1],
                'sender_email' => $identity['email'],
                'sender_name' => $connection->sender_name ?: $organization->name,
                'verification_status' => EmailVerificationStatus::Verified,
                'verified_at' => now(),
                'credentials' => ['refresh_token' => $tokens['refresh_token']],
                // Bounce notices can only be found when the business granted read access to its mailbox.
                'bounce_scan_enabled' => $this->grantsMailboxRead($provider, $tokens['scope']),
                'connection_status' => EmailConnection::STATUS_ACTIVE,
                'status_message' => null,
                'last_tested_at' => now(),
            ])->save();

            Log::info('email.account_connected', ['event' => 'email.account_connected', 'organization_id' => $organization->id, 'provider' => $provider->value, 'connection_id' => $connection->id]);

            return $connection;
        });
    }

    private function grantsMailboxRead(EmailProvider $provider, ?string $scope): bool
    {
        $granted = (string) $scope;

        return match ($provider) {
            EmailProvider::Gmail => str_contains($granted, 'gmail.readonly'),
            EmailProvider::Microsoft => stripos($granted, 'Mail.Read') !== false,
            default => false,
        };
    }
}

<?php

namespace App\Services\Email;

use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use App\Enums\Team\Permission;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\Providers\SmtpEmailProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Connects a business's own SMTP account. The settings are tested before anything is saved, and the password is
 * stored encrypted. Only an owner or someone who manages email may connect.
 */
class SmtpConnectionService
{
    public function __construct(private readonly SmtpEmailProvider $smtp) {}

    /**
     * @param  array<string, mixed>  $input  host, port, encryption, username, password, sender_name
     *
     * @throws ValidationException|EmailProviderException
     */
    public function connect(User $actor, Organization $organization, array $input): EmailConnection
    {
        $this->assertCanManage($actor, $organization);

        try {
            $settings = SmtpSettings::fromInput($input);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['smtp' => $e->getMessage()]);
        }

        $senderName = trim((string) ($input['sender_name'] ?? ''));

        if ($senderName === '' || mb_strlen($senderName) > 255) {
            throw ValidationException::withMessages(['sender_name' => 'Enter the name customers should see.']);
        }

        // Most providers only let an account send as its own address, so the sender is the login address.
        $senderEmail = Str::lower(trim($settings->username));

        if (! filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['username' => 'Use the email address of this account as the username.']);
        }

        $this->smtp->test($settings);

        return DB::transaction(function () use ($organization, $settings, $senderName, $senderEmail) {
            $connection = EmailConnection::query()
                ->where('organization_id', $organization->id)
                ->where('provider', EmailProvider::Smtp)
                ->where('sender_email', $senderEmail)
                ->lockForUpdate()
                ->first() ?? new EmailConnection;

            $connection->forceFill([
                'organization_id' => $organization->id,
                'provider' => EmailProvider::Smtp,
                'domain' => Str::after($senderEmail, '@'),
                'sender_email' => $senderEmail,
                'sender_name' => $senderName,
                'verification_status' => EmailVerificationStatus::Verified,
                'verified_at' => now(),
                'credentials' => $settings->toCredentials(),
                'connection_status' => EmailConnection::STATUS_ACTIVE,
                'status_message' => null,
                'last_tested_at' => now(),
            ])->save();

            Log::info('email.account_connected', ['event' => 'email.account_connected', 'organization_id' => $organization->id, 'provider' => 'smtp', 'connection_id' => $connection->id]);

            return $connection;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertCanManage(User $actor, Organization $organization): void
    {
        if ($actor->organization_id !== $organization->id || ! $actor->hasPermission(Permission::ManageEmail)) {
            throw ValidationException::withMessages(['smtp' => 'Only the business owner can change email settings.']);
        }
    }
}

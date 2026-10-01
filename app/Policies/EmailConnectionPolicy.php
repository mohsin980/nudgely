<?php

namespace App\Policies;

use App\Models\EmailConnection;
use App\Models\User;

/**
 * Email settings are managed by organization admins, and only for their own organization.
 */
class EmailConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrganizationAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isOrganizationAdmin();
    }

    public function update(User $user, EmailConnection $connection): bool
    {
        return $this->managesConnection($user, $connection);
    }

    public function delete(User $user, EmailConnection $connection): bool
    {
        return $this->managesConnection($user, $connection);
    }

    public function setDefault(User $user, EmailConnection $connection): bool
    {
        return $this->managesConnection($user, $connection);
    }

    /**
     * Register the domain with the email provider, view its DNS records and check verification.
     */
    public function verify(User $user, EmailConnection $connection): bool
    {
        return $this->managesConnection($user, $connection);
    }

    /**
     * Send a fixed test email from this connection. Eligibility (verified sender) is checked by EmailService.
     */
    public function sendTestEmail(User $user, EmailConnection $connection): bool
    {
        return $this->managesConnection($user, $connection);
    }

    private function managesConnection(User $user, EmailConnection $connection): bool
    {
        return $user->isOrganizationAdmin() && $connection->organization_id === $user->organization_id;
    }
}

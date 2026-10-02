<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Any member of an organization can read its conversations; nobody can read another organization's.
 * Only admins can request reclassification.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->organization_id !== null && $conversation->organization_id === $user->organization_id;
    }

    /**
     * Request a fresh AI classification of a reply in this conversation (admins only).
     */
    public function reclassify(User $user, Conversation $conversation): bool
    {
        return $user->isOrganizationAdmin() && $conversation->organization_id === $user->organization_id;
    }
}

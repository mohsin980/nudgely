<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Any member of an organization can read and work its conversations (reply, change status,
 * close/reopen, correct a classification); nobody can touch another organization's.
 * Only admins can request a paid AI reclassification.
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

    /**
     * Reply, change status, close/reopen, correct the AI classification, add tasks.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}

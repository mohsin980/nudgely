<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Any member of an organization can read its conversations; nobody can read another organization's.
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
}

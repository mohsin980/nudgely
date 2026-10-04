<?php

namespace App\Policies;

use App\Enums\Team\Permission;
use App\Models\Conversation;
use App\Models\User;

/**
 * Any member of an organization can read and work its conversations (reply, change status,
 * close/reopen, correct a classification); nobody can touch another organization's.
 * Only owners and managers can request a paid AI reclassification.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->isActiveMember() && $conversation->organization_id === $user->organization_id;
    }

    /**
     * Request a fresh AI classification of a reply in this conversation.
     */
    public function reclassify(User $user, Conversation $conversation): bool
    {
        return $user->hasPermission(Permission::ReclassifyReplies) && $conversation->organization_id === $user->organization_id;
    }

    /**
     * Reply, change status, close/reopen, correct the AI classification, add tasks.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}

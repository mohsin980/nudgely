<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Anyone in the organization works with its customers; nobody sees another organization's.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->isActiveMember() && $customer->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }
}

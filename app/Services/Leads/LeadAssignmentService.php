<?php

namespace App\Services\Leads;

use App\Enums\OrganizationRole;
use App\Enums\Team\NotificationType;
use App\Models\Customer;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Services\Team\TeamNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Gives a lead to a team member to handle (e.g. after the AI assistant hands a chat over). The owner and managers
 * assign anyone; staff can take an unassigned lead for themselves. The new assignee is notified.
 */
class LeadAssignmentService
{
    public function __construct(private readonly TeamNotifier $notifier) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function assign(User $actor, Customer $customer, ?User $assignee): Customer
    {
        if ($actor->organization_id === null || $actor->organization_id !== $customer->organization_id || ! $actor->isActiveMember()) {
            abort(404);
        }

        if (! $this->canAssign($actor, $customer, $assignee)) {
            throw new AuthorizationException('Only the owner or a manager can give a lead to someone else.');
        }

        if ($assignee !== null && ($assignee->organization_id !== $customer->organization_id || ! $assignee->isActiveMember())) {
            throw ValidationException::withMessages(['assignee' => 'Choose an active member of your team.']);
        }

        if ($customer->assigned_to === $assignee?->id) {
            return $customer;
        }

        $customer->forceFill(['assigned_to' => $assignee?->id])->save();
        OrganizationActivity::record($customer->organization, 'lead_assigned', $actor, ['customer_id' => $customer->id, 'assigned_to' => $assignee?->id]);
        Log::info('Lead assigned.', ['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'assigned_to' => $assignee?->id]);

        if ($assignee !== null && $assignee->id !== $actor->id) {
            $this->notifier->notify($customer->organization, NotificationType::LeadAssigned, [$assignee],
                $actor->name.' gave you a lead: '.$customer->name.'.', route('customers.show', $customer->id),
                'lead-assigned:'.$customer->id.':'.$assignee->id.':'.now()->timestamp, $actor, ['customer_id' => $customer->id]);
        }

        return $customer;
    }

    /**
     * The owner and managers can give any lead to anyone.
     */
    public function canAssignAnyone(User $actor): bool
    {
        return in_array($actor->role, [OrganizationRole::Owner, OrganizationRole::Manager], true);
    }

    /**
     * Owner and managers: anyone. Staff: only take an unassigned lead, or give back their own.
     */
    public function canAssign(User $actor, Customer $customer, ?User $assignee = null): bool
    {
        if ($this->canAssignAnyone($actor)) {
            return true;
        }

        return ($assignee?->id === $actor->id && $customer->assigned_to === null)
            || ($assignee === null && $customer->assigned_to === $actor->id);
    }
}

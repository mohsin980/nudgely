<?php

namespace App\Services\Platform;

use App\Exceptions\Platform\OrganizationStateException;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Suspends and reactivates organizations. Suspension is reversible and deletes nothing: members are signed
 * out on their next request and cannot sign back in; data, subscription and billing are untouched.
 *
 * The actor's permission is checked here (not only in the UI), a reason is mandatory, and every change is
 * written to the platform audit log in the same transaction.
 */
class OrganizationSuspension
{
    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    /**
     * @throws AuthorizationException
     * @throws OrganizationStateException
     */
    public function suspend(Organization $organization, User $actor, string $reason): Organization
    {
        Gate::forUser($actor)->authorize('suspend', $organization);

        $reason = $this->cleanReason($reason);

        if ($actor->organization_id === $organization->id) {
            throw OrganizationStateException::ownOrganization();
        }

        return DB::transaction(function () use ($organization, $actor, $reason) {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if ($locked->isSuspended()) {
                throw OrganizationStateException::alreadySuspended();
            }

            $locked->forceFill(['suspended_at' => now(), 'suspended_by' => $actor->id])->save();
            PlatformAuditLog::record('organization_suspended', $actor, $locked->id, $reason);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws OrganizationStateException
     */
    public function reactivate(Organization $organization, User $actor, string $reason): Organization
    {
        Gate::forUser($actor)->authorize('reactivate', $organization);

        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($organization, $actor, $reason) {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if (! $locked->isSuspended()) {
                throw OrganizationStateException::notSuspended();
            }

            $locked->forceFill(['suspended_at' => null, 'suspended_by' => null])->save();
            PlatformAuditLog::record('organization_reactivated', $actor, $locked->id, $reason);

            return $locked;
        });
    }

    /**
     * @throws OrganizationStateException
     */
    private function cleanReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $length = mb_strlen($reason);

        if ($length < self::MIN_REASON || $length > self::MAX_REASON) {
            throw OrganizationStateException::invalidReason(self::MIN_REASON, self::MAX_REASON);
        }

        return $reason;
    }
}

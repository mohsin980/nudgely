<?php

namespace App\Services\Platform;

use App\Enums\Billing\LimitKey;
use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Platform\UserAccountException;
use App\Models\OrganizationActivity;
use App\Models\PlatformAuditLog;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Platform\Concerns\AuditsDeniedAttempts;
use App\Services\Team\TeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates and reactivates user accounts from the Super Admin panel, using the account lifecycle the customer
 * app already has (MemberStatus::Suspended). Deactivation ends every session and remember-me token at once; the
 * status is also read fresh on each request (EnsureActiveMember), so an open page is refused on the next click.
 *
 * Permission is checked here, not only in the UI. A reason is mandatory and each change is written to the
 * platform audit log in the same transaction. Passwords, tokens and roles are never touched.
 */
class UserAccountService
{
    use AuditsDeniedAttempts;

    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public function __construct(private readonly TeamService $team) {}

    /**
     * @throws AuthorizationException
     * @throws UserAccountException
     */
    public function deactivate(User $target, User $actor, string $reason): User
    {
        $this->authorizeAudited($actor, 'deactivate', $target, 'user_deactivated', ['type' => 'user', 'id' => $target->id, 'subject_user_id' => $target->id, 'organization_id' => $target->organization_id]);
        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($target, $actor, $reason) {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->role === OrganizationRole::Owner && $locked->organization_id !== null) {
                throw UserAccountException::owner();
            }

            if ($locked->status !== MemberStatus::Active) {
                throw UserAccountException::notActive();
            }

            $locked->forceFill(['status' => MemberStatus::Suspended, 'suspended_at' => now()])->save();
            $this->team->endSessions($locked);

            $this->record('user_deactivated', 'member_suspended', $actor, $locked, $reason);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws UserAccountException
     */
    public function reactivate(User $target, User $actor, string $reason): User
    {
        $this->authorizeAudited($actor, 'reactivate', $target, 'user_reactivated', ['type' => 'user', 'id' => $target->id, 'subject_user_id' => $target->id, 'organization_id' => $target->organization_id]);
        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($target, $actor, $reason) {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->status !== MemberStatus::Suspended) {
                throw UserAccountException::notSuspended();
            }

            $restore = function () use ($locked, $actor, $reason) {
                $locked->forceFill(['status' => MemberStatus::Active, 'suspended_at' => null])->save();
                $this->record('user_reactivated', 'member_reactivated', $actor, $locked, $reason);
            };

            // Same rule as the customer app: coming back takes a seat again, and the plan may have changed.
            if ($locked->organization_id === null) {
                $restore();

                return $locked;
            }

            try {
                app(EntitlementService::class)->guard($locked->organization, LimitKey::TeamMembers, $restore,
                    fn () => TeamInvitation::query()->where('organization_id', $locked->organization_id)->open()->count());
            } catch (PlanLimitException $e) {
                throw new UserAccountException($e->getMessage());
            }

            return $locked;
        });
    }

    /** Platform audit row (with the internal reason) plus a reason-free entry in the business's own history. */
    private function record(string $platformAction, string $businessAction, User $actor, User $subject, string $reason): void
    {
        PlatformAuditLog::record($platformAction, $actor, $subject->organization_id, $reason, $subject->id);

        if ($subject->organization_id !== null) {
            OrganizationActivity::record($subject->organization_id, $businessAction, null, [], $subject);
        }
    }

    /**
     * @throws UserAccountException
     */
    private function cleanReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $length = mb_strlen($reason);

        if ($length < self::MIN_REASON || $length > self::MAX_REASON) {
            throw UserAccountException::invalidReason(self::MIN_REASON, self::MAX_REASON);
        }

        return $reason;
    }
}

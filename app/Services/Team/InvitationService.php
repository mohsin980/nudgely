<?php

namespace App\Services\Team;

use App\Enums\Billing\LimitKey;
use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\Team\TeamActionException;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Email\EmailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Invite people to the team. The link carries a random 64-character token; only its sha256 is
 * stored. A token works once, until it expires (config team.invitation_days), and stops working
 * when the invitation is revoked or resent. The organization and role come from the invitation
 * record, never from the link or the form.
 */
class InvitationService
{
    public function __construct(private readonly EmailService $email) {}

    /**
     * @return array{invitation: TeamInvitation, link: string, emailed: bool}
     *
     * @throws AuthorizationException|TeamActionException
     */
    public function invite(User $actor, string $name, string $email, OrganizationRole $role): array
    {
        $this->authorize($actor);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $email = Str::lower(trim($email));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new TeamActionException('Enter a name (up to 100 characters).');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new TeamActionException('Enter a valid email address.');
        }

        if (! in_array($role, OrganizationRole::assignable(), true)) {
            throw new TeamActionException('Choose manager or staff.');
        }

        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null && ($existing->organization_id !== $actor->organization_id || $existing->status !== MemberStatus::Removed)) {
            throw new TeamActionException($existing->organization_id === $actor->organization_id
                ? 'This person is already on your team.'
                : 'This email is already used by another QuoteFollow account.');
        }

        if (TeamInvitation::query()->where('organization_id', $actor->organization_id)->open()->where('email', $email)->exists()) {
            throw new TeamActionException('This person already has an invitation. Resend it instead.');
        }

        if (TeamInvitation::query()->where('organization_id', $actor->organization_id)->open()->count() >= (int) config('team.max_open_invitations')) {
            throw new TeamActionException('Too many open invitations. Revoke some first.');
        }

        // Open invitations are promised seats, so they count against the plan's team limit; the check and the insert share the organization's lock.
        try {
            [$invitation, $token] = app(EntitlementService::class)->guard(
                $actor->organization,
                LimitKey::TeamMembers,
                function () use ($actor, $name, $email, $role) {
                    [$invitation, $token] = $this->create($actor, $name, $email, $role);
                    OrganizationActivity::record($actor->organization_id, 'member_invited', $actor, ['name' => $name, 'email' => $email, 'role' => $role->value]);

                    return [$invitation, $token];
                },
                fn () => TeamInvitation::query()->where('organization_id', $actor->organization_id)->open()->count(),
            );
        } catch (PlanLimitException $e) {
            throw new TeamActionException($e->getMessage());
        }

        return ['invitation' => $invitation, 'link' => $this->link($token), 'emailed' => $this->sendEmail($invitation, $token, $actor)];
    }

    /**
     * A new link and expiry; the old link stops working.
     *
     * @return array{invitation: TeamInvitation, link: string, emailed: bool}
     *
     * @throws AuthorizationException|TeamActionException
     */
    public function resend(User $actor, int $invitationId): array
    {
        $this->authorize($actor);

        [$invitation, $token] = DB::transaction(function () use ($actor, $invitationId) {
            $old = TeamInvitation::query()->where('organization_id', $actor->organization_id)->open()->lockForUpdate()->find($invitationId)
                ?? throw new TeamActionException('Invitation not found.');
            $old->forceFill(['revoked_at' => now()])->save();
            [$invitation, $token] = $this->create($actor, $old->name, $old->email, $old->role);
            OrganizationActivity::record($actor->organization_id, 'invitation_resent', $actor, ['email' => $old->email]);

            return [$invitation, $token];
        });

        return ['invitation' => $invitation, 'link' => $this->link($token), 'emailed' => $this->sendEmail($invitation, $token, $actor)];
    }

    /**
     * @throws AuthorizationException|TeamActionException
     */
    public function revoke(User $actor, int $invitationId): void
    {
        $this->authorize($actor);

        $revoked = TeamInvitation::query()->where('organization_id', $actor->organization_id)->open()->whereKey($invitationId)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($revoked === 0) {
            throw new TeamActionException('Invitation not found.');
        }

        OrganizationActivity::record($actor->organization_id, 'invitation_revoked', $actor, ['invitation_id' => $invitationId]);
    }

    /**
     * The invitation for a link token (any state), or null.
     */
    public function find(string $token): ?TeamInvitation
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $token)
            ? TeamInvitation::query()->with('organization')->where('token_hash', TeamInvitation::hashToken($token))->first()
            : null;
    }

    /**
     * Accept: create the account (or bring back a removed member of the same business) with the
     * invited role. Single use: the invitation row is locked and marked accepted in one transaction.
     *
     * @throws TeamActionException
     */
    public function accept(string $token, string $name, string $password): User
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new TeamActionException('Enter your name (up to 100 characters).');
        }

        return DB::transaction(function () use ($token, $name, $password) {
            $invitation = preg_match('/^[A-Za-z0-9]{64}$/', $token)
                ? TeamInvitation::query()->where('token_hash', TeamInvitation::hashToken($token))->lockForUpdate()->first()
                : null;

            if ($invitation === null || ! $invitation->isOpen()) {
                throw new TeamActionException('This invitation is no longer valid.');
            }

            if ($invitation->isExpired()) {
                throw new TeamActionException('This invitation has expired.');
            }

            $user = User::query()->whereRaw('lower(email) = ?', [$invitation->email])->lockForUpdate()->first();

            if ($user !== null && ($user->organization_id !== $invitation->organization_id || $user->status !== MemberStatus::Removed)) {
                throw new TeamActionException('This email is already used by another QuoteFollow account.');
            }

            // A new (or returning) person takes a seat; the plan may have shrunk since the invitation.
            // The check and the activation share the organization's lock.
            try {
                return app(EntitlementService::class)->guard(Organization::findOrFail($invitation->organization_id), LimitKey::TeamMembers, function () use ($user, $invitation, $name, $password) {
                    $user ??= new User;
                    $user->forceFill([
                        'name' => $name,
                        'email' => $invitation->email,
                        'password' => Hash::make($password),
                        'organization_id' => $invitation->organization_id,
                        'role' => $invitation->role,
                        'status' => MemberStatus::Active,
                        'suspended_at' => null,
                        'removed_at' => null,
                        // The emailed link proves the address.
                        'email_verified_at' => now(),
                    ])->save();

                    $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
                    OrganizationActivity::record($invitation->organization_id, 'invitation_accepted', $user, ['role' => $invitation->role->value], $user);
                    Log::info('Invitation accepted.', ['organization_id' => $invitation->organization_id, 'user_id' => $user->id, 'invitation_id' => $invitation->id]);

                    return $user;
                });
            } catch (PlanLimitException $e) {
                throw new TeamActionException($e->getMessage());
            }
        });
    }

    /**
     * @return array{0: TeamInvitation, 1: string}
     */
    private function create(User $actor, string $name, string $email, OrganizationRole $role): array
    {
        $token = Str::random(64);
        $invitation = new TeamInvitation;
        $invitation->forceFill([
            'organization_id' => $actor->organization_id,
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'token_hash' => TeamInvitation::hashToken($token),
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays((int) config('team.invitation_days')),
        ])->save();

        return [$invitation, $token];
    }

    /**
     * Queue the invitation email from the business's verified sender. False when the business
     * can't send email yet (the person who invited gets the link to share instead).
     */
    private function sendEmail(TeamInvitation $invitation, string $token, User $inviter): bool
    {
        $organization = Organization::findOrFail($invitation->organization_id);
        $firstName = explode(' ', $invitation->name)[0];
        $link = $this->link($token);
        $days = (int) config('team.invitation_days');
        $text = "Hi {$firstName},\n\n{$inviter->name} invited you to join {$organization->name} on QuoteFollow.\n\n"
            ."You've been invited as: {$invitation->role->label()}\n\nAccept the invitation:\n{$link}\n\n"
            ."This link works once and expires in {$days} days. If you weren't expecting this, you can ignore this email.";
        $html = '<p>Hi '.e($firstName).',</p><p>'.e($inviter->name).' invited you to join <strong>'.e($organization->name).'</strong> on QuoteFollow.</p>'
            .'<p>You\'ve been invited as: <strong>'.e($invitation->role->label()).'</strong></p>'
            .'<p><a href="'.e($link).'">Accept Invitation</a></p>'
            .'<p>This link works once and expires in '.$days.' days. If you weren\'t expecting this, you can ignore this email.</p>';

        try {
            // The link is a credential: EmailService removes the body once it is sent.
            $this->email->send($organization, $invitation->email, "You've been invited to join {$organization->name}", $html, $text,
                toName: $invitation->name, metadata: ['type' => 'team_invitation', 'team_invitation_id' => (string) $invitation->id, 'redact_after_send' => '1']);
        } catch (EmailSendingNotAllowedException $e) {
            Log::info('Invitation email not sent: no verified sender.', ['organization_id' => $organization->id, 'invitation_id' => $invitation->id]);

            return false;
        }

        return true;
    }

    private function link(string $token): string
    {
        return route('invitations.show', $token);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor): void
    {
        if (! $actor->hasPermission(Permission::ManageTeam)) {
            throw new AuthorizationException('You can’t manage the team.');
        }
    }
}

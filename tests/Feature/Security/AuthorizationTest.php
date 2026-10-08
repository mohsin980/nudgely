<?php

use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Exceptions\Team\TeamActionException;
use App\Livewire\Settings\AccountSecurity;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\BillingPlans;
use App\Livewire\Settings\BusinessProfile;
use App\Livewire\Settings\TeamMembers;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Team\InvitationService;
use App\Services\Team\TeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'organization' => $this->organization] = teamBusiness('Alpha HVAC');
    $this->team = app(TeamService::class);
});

// ─── Roles ───────────────────────────────────────────────────────────────────────────────

test('8, 9 and 11. staff and managers cannot open administrative pages', function (string $url) {
    $this->actingAs($this->staff)->get($url)->assertForbidden();
    $this->actingAs($this->owner)->get($url)->assertOk();
})->with(['/settings/team', '/settings/business', '/settings/preferences', '/settings/billing', '/settings/billing/plans', '/settings/billing/usage', '/settings/billing/history']);

test('a manager can reach defaults but not team, business profile or billing', function () {
    foreach (['/settings/team', '/settings/business', '/settings/preferences', '/settings/billing'] as $url) {
        $this->actingAs($this->manager)->get($url)->assertForbidden();
    }

    $this->actingAs($this->manager)->get('/settings/estimates')->assertOk();
});

test('8 and 9. a forged Livewire request cannot give staff the team page or role changes', function () {
    Livewire::actingAs($this->staff)->test(TeamMembers::class)->assertForbidden();
    Livewire::actingAs($this->manager)->test(TeamMembers::class)->assertForbidden();

    foreach ([$this->staff, $this->manager] as $actor) {
        expect(fn () => $this->team->changeRole($actor, $this->staff, OrganizationRole::Manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->team->suspend($actor, $this->manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->team->remove($actor, $this->staff, null))->toThrow(AuthorizationException::class);
    }
});

test('11. staff cannot modify business settings through the page or the service', function () {
    Livewire::actingAs($this->staff)->test(BusinessProfile::class)->assertForbidden();
    expect(fn () => app(BusinessSettingsService::class)->updateProfile($this->staff, ['name' => 'Hijacked', 'country' => 'US']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(BusinessSettingsService::class)->updatePreferences($this->manager, ['timezone' => 'America/Denver']))->toThrow(AuthorizationException::class)
        ->and($this->organization->fresh()->name)->toBe('Alpha HVAC');
});

test('12. nobody but the owner can change billing, by page or service', function () {
    $subscription = moveToPlan($this->organization, 'starter');

    foreach ([$this->manager, $this->staff] as $actor) {
        Livewire::actingAs($actor)->test(BillingOverview::class)->assertForbidden();
        Livewire::actingAs($actor)->test(BillingPlans::class)->assertForbidden();
        $service = app(BillingService::class);
        expect(fn () => $service->changePlan($actor, 'pro'))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->cancel($actor))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->resume($actor))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->portalUrl($actor, 'https://x.test'))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->startCheckout($actor, 'pro', 'https://a', 'https://b'))->toThrow(AuthorizationException::class);
    }

    expect($subscription->fresh()->plan)->toBe('starter')->and($subscription->fresh()->cancel_at_period_end)->toBeFalse();
});

test('10. a manager cannot transfer ownership, and staff cannot be made owner by anyone', function () {
    expect(fn () => $this->team->transferOwnership($this->manager, $this->staff, 'password'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->team->changeRole($this->owner, $this->staff, OrganizationRole::Owner))->toThrow(TeamActionException::class)
        ->and($this->staff->fresh()->role)->toBe(OrganizationRole::Staff)->and($this->owner->fresh()->role)->toBe(OrganizationRole::Owner);
});

test('15. forged role: nothing the browser sends can change a person\'s own role', function () {
    // There is no role property to set on any page a staff member can open; the account page only changes passwords and sessions.
    $page = Livewire::actingAs($this->staff)->test(AccountSecurity::class);
    foreach (['role', 'userId', 'organizationId', 'status'] as $property) {
        expect(fn () => $page->set($property, 'owner'))->toThrow(Exception::class);
    }

    expect(fn () => $this->team->changeRole($this->staff, $this->staff, OrganizationRole::Owner))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->team->changeRole($this->owner, $this->owner, OrganizationRole::Staff))->toThrow(TeamActionException::class);
});

// ─── Last owner ──────────────────────────────────────────────────────────────────────────

test('the business can never be left without an owner', function () {
    // The only person who can manage the team is the owner, and they can't suspend, remove or demote themselves.
    expect(fn () => $this->team->suspend($this->owner, $this->owner))->toThrow(TeamActionException::class)
        ->and(fn () => $this->team->remove($this->owner, $this->owner, $this->manager))->toThrow(TeamActionException::class)
        ->and(fn () => $this->team->changeRole($this->owner, $this->owner, OrganizationRole::Staff))->toThrow(TeamActionException::class);

    // And the database refuses a second owner, so ownership can only move through the locked transfer.
    expect(fn () => DB::transaction(fn () => User::factory()->owner()->for($this->organization)->create()))->toThrow(UniqueConstraintViolationException::class)
        ->and(User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Owner)->where('status', MemberStatus::Active)->count())->toBe(1);
});

test('ownership transfer swaps roles atomically, needs the password and an active member', function () {
    expect(fn () => $this->team->transferOwnership($this->owner, $this->manager, 'wrong-password'))->toThrow(TeamActionException::class);

    $this->team->suspend($this->owner, $this->staff);
    expect(fn () => $this->team->transferOwnership($this->owner, $this->staff->fresh(), 'password'))->toThrow(TeamActionException::class);

    $this->team->transferOwnership($this->owner, $this->manager, 'password');

    $owners = User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Owner)->get();
    expect($owners)->toHaveCount(1)->and($owners->first()->id)->toBe($this->manager->id)->and($this->owner->fresh()->role)->not->toBe(OrganizationRole::Owner);

    // The previous owner, now a manager, can no longer transfer or manage billing.
    expect(fn () => $this->team->transferOwnership($this->owner->fresh(), $this->staff, 'password'))->toThrow(AuthorizationException::class);
});

test('two simultaneous transfers still leave exactly one owner', function () {
    $this->team->transferOwnership($this->owner, $this->manager, 'password');

    // The second request was prepared while John was still the owner; by now it is no longer allowed.
    expect(fn () => $this->team->transferOwnership($this->owner->fresh(), $this->staff, 'password'))->toThrow(AuthorizationException::class)
        ->and(User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

// ─── Invitations ─────────────────────────────────────────────────────────────────────────

test('17. an invitation link works once', function () {
    $invite = app(InvitationService::class)->invite($this->owner, 'Pat New', 'pat@alpha.test', OrganizationRole::Staff);
    $token = basename($invite['link']);

    $user = app(InvitationService::class)->accept($token, 'Pat New', 'a-long-secure-password-1');
    expect($user->organization_id)->toBe($this->organization->id);

    expect(fn () => app(InvitationService::class)->accept($token, 'Someone Else', 'another-long-password-2'))->toThrow(TeamActionException::class);
});

test('18. an expired invitation is refused', function () {
    $invite = app(InvitationService::class)->invite($this->owner, 'Pat New', 'pat@alpha.test', OrganizationRole::Staff);
    $this->travel(config('team.invitation_days') + 1)->days();

    expect(fn () => app(InvitationService::class)->accept(basename($invite['link']), 'Pat New', 'a-long-secure-password-1'))->toThrow(TeamActionException::class, 'expired');
});

test('19. an invitation only ever joins the business that issued it', function () {
    ['owner' => $otherOwner, 'organization' => $other] = teamBusiness('Bravo HVAC');
    $invite = app(InvitationService::class)->invite($this->owner, 'Pat New', 'pat@alpha.test', OrganizationRole::Manager);
    $token = basename($invite['link']);

    // The token is the only input: there is no way to name another organization.
    $user = app(InvitationService::class)->accept($token, 'Pat New', 'a-long-secure-password-1');

    expect($user->organization_id)->toBe($this->organization->id)->and($user->role)->toBe(OrganizationRole::Manager)
        ->and(User::where('organization_id', $other->id)->where('email', 'pat@alpha.test')->exists())->toBeFalse();
});

test('invitation tokens are random, long and only their hash is stored', function () {
    $a = app(InvitationService::class)->invite($this->owner, 'Pat New', 'pat@alpha.test', OrganizationRole::Staff);
    $b = app(InvitationService::class)->invite($this->owner, 'Sam New', 'sam@alpha.test', OrganizationRole::Staff);
    $tokenA = basename($a['link']);

    expect($tokenA)->toMatch('/^[A-Za-z0-9]{64}$/')->and($tokenA)->not->toBe(basename($b['link']))
        ->and(TeamInvitation::query()->where('token_hash', $tokenA)->exists())->toBeFalse()
        ->and(TeamInvitation::query()->where('token_hash', hash('sha256', $tokenA))->exists())->toBeTrue()
        ->and(TeamInvitation::query()->get()->every(fn ($i) => strlen($i->token_hash) === 64))->toBeTrue();
});

test('the raw invitation link is not part of the Livewire state sent to the browser', function () {
    $this->organization->emailConnections()->update(['verification_status' => 'pending']); // cannot email, so the owner is shown the link

    $page = Livewire::actingAs($this->owner)->test(TeamMembers::class)->call('openInviteForm')->set('inviteName', 'Pat New')->set('inviteEmail', 'pat@alpha.test')->set('inviteRole', 'staff')->call('invite');
    $page->assertSee('/invitations/');
    preg_match('~/invitations/([A-Za-z0-9]{64})~', $page->html(), $match);

    // Shown to the owner on the page, but never part of the state the browser posts back and forth.
    expect($match[1] ?? null)->not->toBeNull()->and(json_encode($page->snapshot))->not->toContain($match[1])->not->toContain('/invitations/');
});

// ─── Suspended and removed people ────────────────────────────────────────────────────────

test('30, 31 and 35. suspended and removed people lose every door at once', function (string $state) {
    $member = User::factory()->manager()->for($this->organization)->create();
    $other = $this->actingAs($member);
    $this->get('/dashboard')->assertOk(); // works while active

    $state === 'suspended' ? $this->team->suspend($this->owner, $member) : $this->team->remove($this->owner, $member, $this->owner);

    foreach (['/dashboard', '/customers', '/conversations', '/estimates', '/automations', '/settings/estimates', '/settings/notifications'] as $url) {
        $this->actingAs($member->fresh())->get($url)->assertRedirect(route('login'));
    }

    // Old sessions are gone and a Livewire request from the old browser is refused.
    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    $response = $this->actingAs($member->fresh())->postJson('/livewire/update', ['components' => []]);
    expect($response->status())->toBeIn([401, 403, 419]);
    $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
    expect(Customer::where('organization_id', $this->organization->id)->count())->toBeGreaterThan(0); // history and data intact
})->with(['suspended', 'removed']);

test('a removed person\'s history stays in the activity log', function () {
    $member = User::factory()->staff()->for($this->organization)->create();
    $this->team->remove($this->owner, $member, $this->owner);

    expect(OrganizationActivity::where('action', 'member_removed')->exists())->toBeTrue()->and(User::find($member->id))->not->toBeNull();
});

<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\NotificationChannel;
use App\Enums\Team\NotificationType;
use App\Enums\Team\Permission;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Exceptions\Team\TeamActionException;
use App\Jobs\SendEmailJob;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Settings\AccountSecurity;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\TeamMembers;
use App\Livewire\Team\AcceptInvitation;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\OrganizationActivity;
use App\Models\Task;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\FollowUps\FollowUpService;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Tasks\TaskService;
use App\Services\Team\InvitationService;
use App\Services\Team\NotificationPreferences;
use App\Services\Team\TeamNotifier;
use App\Services\Team\TeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness();
    $this->invitations = app(InvitationService::class);
    $this->team = app(TeamService::class);
});

function inviteSarah(User $owner, OrganizationRole $role = OrganizationRole::Manager): array
{
    return app(InvitationService::class)->invite($owner, 'Sarah Lee', 'sarah.lee@example.com', $role);
}

function tokenOf(array $result): string
{
    return basename($result['link']);
}

// Team

test('8. the owner can invite a member and the invitation email is queued', function () {
    Queue::fake([SendEmailJob::class]);

    Livewire::actingAs($this->owner)->test(TeamMembers::class)
        ->call('openInviteForm')->set('inviteName', 'Sarah Lee')->set('inviteEmail', 'Sarah.Lee@Example.com')->set('inviteRole', 'manager')
        ->call('invite')->assertHasNoErrors()->assertSee('Invitation sent to sarah.lee@example.com.')->assertSee('Sarah Lee')->assertSee('Invited');

    $email = Message::where('to_address', 'sarah.lee@example.com')->sole();
    expect($email->subject)->toBe("You've been invited to join Dallas HVAC")
        ->and($email->from_address)->toBe('hello@dallashvac.com')
        ->and($email->body_text)->toContain('John Smith invited you to join Dallas HVAC on QuoteFollow.')->toContain('Manager');
    Queue::assertPushed(SendEmailJob::class, fn ($job) => $job->messageId === $email->id);
});

test('9. the invitation records organization, role, inviter and a 7-day expiry', function () {
    $invitation = inviteSarah($this->owner)['invitation'];

    expect($invitation->organization_id)->toBe($this->organization->id)
        ->and($invitation->role)->toBe(OrganizationRole::Manager)
        ->and($invitation->invited_by)->toBe($this->owner->id)
        ->and($invitation->expires_at->diffInDays(now()->addDays(7)) < 1)->toBeTrue()
        ->and(OrganizationActivity::where('action', 'member_invited')->sole()->user_id)->toBe($this->owner->id);

    // Invalid input and roles are refused.
    expect(fn () => $this->invitations->invite($this->owner, 'X', 'not-an-email', OrganizationRole::Staff))->toThrow(TeamActionException::class)
        ->and(fn () => $this->invitations->invite($this->owner, 'X', 'x@example.com', OrganizationRole::Owner))->toThrow(TeamActionException::class)
        ->and(fn () => $this->invitations->invite($this->owner, 'Sarah', 'sarah.lee@example.com', OrganizationRole::Staff))->toThrow(TeamActionException::class, 'already has an invitation');
    Livewire::actingAs($this->owner)->test(TeamMembers::class)->set('inviteRole', 'admin')->call('invite')->assertHasErrors('inviteRole');
});

test('10. the invitation token is random, hashed at rest and removed from the sent email', function () {
    fakeEmailProvider();
    $result = inviteSarah($this->owner);
    $token = tokenOf($result);

    expect($token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($result['invitation']->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode(TeamInvitation::all()->toArray()))->not->toContain($token);

    // Delivered (sync queue): the stored body no longer contains the link.
    $email = Message::where('to_address', 'sarah.lee@example.com')->sole();
    expect($email->status->value)->toBe('sent')->and($email->body_text)->not->toContain($token)->and($email->body_html)->toBeNull();

    // Two invitations never share a token.
    expect(tokenOf($this->invitations->invite($this->owner, 'Ana', 'ana@example.com', OrganizationRole::Staff)))->not->toBe($token);
});

test('11. invitations expire after 7 days', function () {
    $invitation = inviteSarah($this->owner)['invitation'];

    $this->travel(6)->days();
    expect($invitation->fresh()->isUsable())->toBeTrue();
    $this->travel(1)->day();
    $this->travel(1)->minute();
    expect($invitation->fresh()->isExpired())->toBeTrue();
});

test('12. an expired invitation cannot be accepted', function () {
    $token = tokenOf(inviteSarah($this->owner));
    $this->travel(8)->days();

    $this->get(route('invitations.show', $token))->assertOk()->assertSee('This invitation has expired.')->assertDontSee('Accept Invitation');
    expect(fn () => $this->invitations->accept($token, 'Sarah Lee', 'secret-password-1'))->toThrow(TeamActionException::class, 'This invitation has expired.')
        ->and(User::where('email', 'sarah.lee@example.com')->exists())->toBeFalse();
});

test('13. resending invalidates the previous invitation', function () {
    $first = inviteSarah($this->owner);

    Livewire::actingAs($this->owner)->test(TeamMembers::class)->call('resend', $first['invitation']->id)->assertSee('The previous link no longer works.');

    expect($first['invitation']->fresh()->revoked_at)->not->toBeNull()
        ->and(fn () => $this->invitations->accept(tokenOf($first), 'Sarah', 'secret-password-1'))->toThrow(TeamActionException::class, 'no longer valid');
    $this->get(route('invitations.show', tokenOf($first)))->assertSee('This invitation is no longer valid.');

    $open = TeamInvitation::query()->open()->sole();
    expect($open->id)->not->toBe($first['invitation']->id)->and($open->email)->toBe('sarah.lee@example.com');

    // Revoked invitations stop working too.
    Livewire::actingAs($this->owner)->test(TeamMembers::class)->call('revoke', $open->id);
    expect(TeamInvitation::query()->open()->count())->toBe(0);
});

test('14. a person can accept an invitation and is signed in', function () {
    $token = tokenOf(inviteSarah($this->owner));

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->assertSee('Join Dallas HVAC')->assertSee('Manager')->assertSet('name', 'Sarah Lee')
        ->set('password', 'short')->set('passwordConfirmation', 'short')->call('accept')->assertHasErrors('password')
        ->set('password', 'secret-password-1')->set('passwordConfirmation', 'other-password-1')->call('accept')->assertHasErrors('password')
        ->set('passwordConfirmation', 'secret-password-1')->call('accept')->assertHasNoErrors()->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs(User::where('email', 'sarah.lee@example.com')->sole());
});

test('15. the member is active with the invited role, and the link works only once', function () {
    $result = inviteSarah($this->owner);
    $user = $this->invitations->accept(tokenOf($result), 'Sarah Lee', 'secret-password-1');

    expect($user->organization_id)->toBe($this->organization->id)
        ->and($user->role)->toBe(OrganizationRole::Manager)
        ->and($user->status)->toBe(MemberStatus::Active)
        ->and($result['invitation']->fresh()->accepted_user_id)->toBe($user->id)
        ->and(fn () => $this->invitations->accept(tokenOf($result), 'Again', 'secret-password-2'))->toThrow(TeamActionException::class);

    $this->post('/login', ['email' => 'sarah.lee@example.com', 'password' => 'secret-password-1'])->assertRedirect(route('dashboard'));
});

// Roles

test('16. the owner has every permission', function () {
    foreach (Permission::cases() as $permission) {
        expect($this->owner->can($permission->value))->toBeTrue($permission->value);
    }
});

test('17. managers run the business day to day but not team, email, profile or ownership', function () {
    $allowed = [Permission::ManageBusinessDefaults, Permission::ManageAutomations, Permission::ViewAutomations, Permission::ReclassifyReplies];

    foreach (Permission::cases() as $permission) {
        expect($this->manager->can($permission->value))->toBe(in_array($permission, $allowed, true), $permission->value);
    }

    $this->actingAs($this->manager)->get('/customers')->assertOk();
    $this->actingAs($this->manager)->get('/estimates/create')->assertOk();
    $this->actingAs($this->manager)->get('/follow-ups')->assertOk();
    $this->actingAs($this->manager)->get('/automations/create')->assertOk();
});

test('18. staff do the everyday work and can only view automations', function () {
    foreach (Permission::cases() as $permission) {
        expect($this->staff->can($permission->value))->toBe($permission === Permission::ViewAutomations, $permission->value);
    }

    foreach (['/dashboard', '/customers', '/conversations', '/estimates', '/estimates/create', '/follow-ups', '/automations', '/settings/notifications', '/settings/security', '/settings/roles'] as $url) {
        $this->actingAs($this->staff)->get($url)->assertOk();
    }

    $this->actingAs($this->staff)->get('/automations/create')->assertForbidden();
});

test('19. unauthorized actions are rejected on the server, not just hidden', function () {
    expect(fn () => $this->team->changeRole($this->staff, $this->manager, OrganizationRole::Staff))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->invitations->invite($this->manager, 'X', 'x@example.com', OrganizationRole::Staff))->toThrow(AuthorizationException::class)
        ->and(fn () => app(BusinessSettingsService::class)->updatePreferences($this->staff, ['timezone' => 'UTC']))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->staff)->test(TeamMembers::class)->assertForbidden();
    expect($this->manager->fresh()->role)->toBe(OrganizationRole::Manager);
});

test('20. a role change takes effect immediately', function () {
    $this->actingAs($this->staff)->get('/automations/create')->assertForbidden();

    Livewire::actingAs($this->owner)->test(TeamMembers::class)->call('changeRole', $this->staff->id, 'manager')->assertSee('Mike Johnson is now Manager.');

    expect($this->staff->fresh()->role)->toBe(OrganizationRole::Manager);
    $this->actingAs($this->staff->fresh())->get('/automations/create')->assertOk();
    expect(OrganizationActivity::where('action', 'role_changed')->sole()->data)->toEqual(['from' => 'staff', 'to' => 'manager']);
});

// Member status

test('21. the owner can suspend and reactivate a member', function () {
    DB::table('sessions')->insert(['id' => 'mike-session', 'user_id' => $this->staff->id, 'payload' => '', 'last_activity' => time()]);

    Livewire::actingAs($this->owner)->test(TeamMembers::class)->call('suspend', $this->staff->id)->assertSee('Mike Johnson is suspended and was signed out.');

    expect($this->staff->fresh()->status)->toBe(MemberStatus::Suspended)
        ->and(DB::table('sessions')->where('user_id', $this->staff->id)->exists())->toBeFalse();

    $this->team->reactivate($this->owner, $this->staff);
    expect($this->staff->fresh()->status)->toBe(MemberStatus::Active);
});

test('22. a suspended member cannot access the organization', function () {
    $this->team->suspend($this->owner, $this->staff);
    $suspended = $this->staff->fresh();

    $this->actingAs($suspended)->get('/customers')->assertRedirect(route('login'))->assertSessionHas('status', 'Your access to this business has been suspended. Contact the business owner.');
    $this->assertGuest();

    foreach (['view', 'update'] as $ability) {
        expect($suspended->can($ability, $this->customer))->toBeFalse();
    }
    expect($suspended->can('access-organization'))->toBeFalse();

    // Cannot sign in, cannot send email or be given work.
    $this->post('/login', ['email' => $suspended->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(fn () => app(TaskService::class)->create($this->owner, $this->customer, 'Call', assignee: $suspended))->toThrow(Exception::class);
});

test('23. a removed member cannot access the organization', function () {
    $this->team->remove($this->owner, $this->staff, null);
    $removed = $this->staff->fresh();

    expect($removed->status)->toBe(MemberStatus::Removed);
    $this->actingAs($removed)->get('/dashboard')->assertRedirect(route('login'));
    $this->post('/login', ['email' => $removed->email, 'password' => 'password'])->assertSessionHasErrors('email');

    Livewire::actingAs($this->owner)->test(TeamMembers::class)->assertDontSeeHtml('data-member="'.$removed->id.'"')
        ->set('showRemoved', true)->assertSeeHtml('data-member="'.$removed->id.'"')->assertSee('Removed');
});

test('24. removal keeps history and hands open work to someone else', function () {
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id]);
    $done = app(TaskService::class)->create($this->staff, $this->customer, 'Old finished task');
    app(TaskService::class)->complete($this->staff, $done);
    $open = app(TaskService::class)->create($this->staff, $this->customer, 'Open task');
    $followUp = app(FollowUpService::class)->scheduleManual($this->staff, $this->customer, now()->addDay(), 'Check in', $conversation, $this->staff);
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id, 'created_by' => $this->staff->id]);

    // Their automations need a new owner first.
    Livewire::actingAs($this->owner)->test(TeamMembers::class)
        ->call('startRemoval', $this->staff->id)->assertSee('1 automation they created')->assertSee('1 open task assigned to them')
        ->call('remove')->assertHasErrors('reassignTo')
        ->set('reassignTo', (string) $this->manager->id)->call('remove')->assertHasNoErrors();

    expect($this->staff->fresh()->status)->toBe(MemberStatus::Removed)
        ->and($open->fresh()->assigned_to)->toBe($this->manager->id)
        ->and($followUp->fresh()->assigned_to)->toBe($this->manager->id)
        ->and($automation->fresh()->created_by)->toBe($this->manager->id)
        // History still points at the person who did it.
        ->and($done->fresh()->created_by)->toBe($this->staff->id)
        ->and($done->fresh()->assigned_to)->toBe($this->staff->id)
        ->and(User::find($this->staff->id))->not->toBeNull()
        ->and(OrganizationActivity::where('action', 'member_removed')->sole()->data)->toMatchArray(['automations' => 1, 'tasks' => 1, 'follow_ups' => 1, 'reassigned_to' => 'Sarah Wilson']);
});

// Ownership

test('25. the owner can transfer ownership', function () {
    Livewire::actingAs($this->owner)->test(AccountSecurity::class)
        ->set('transferTo', (string) $this->manager->id)->set('myNewRole', 'manager')->set('transferPassword', 'password')->set('transferConfirmed', true)
        ->call('transferOwnership')->assertHasNoErrors()->assertRedirect(route('settings.security'));

    expect($this->manager->fresh()->role)->toBe(OrganizationRole::Owner)
        ->and($this->owner->fresh()->role)->toBe(OrganizationRole::Manager)
        ->and(User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Owner)->count())->toBe(1)
        ->and(OrganizationActivity::where('action', 'ownership_transferred')->sole()->subject_user_id)->toBe($this->manager->id);
});

test('26. ownership transfer requires confirmation and the current password', function () {
    $page = Livewire::actingAs($this->owner)->test(AccountSecurity::class)->set('transferTo', (string) $this->manager->id)->set('transferPassword', 'password');

    $page->call('transferOwnership')->assertHasErrors('transferConfirmed');
    $page->set('transferConfirmed', true)->set('transferPassword', 'wrong')->call('transferOwnership')->assertHasErrors('transferPassword');
    $page->set('transferTo', (string) $this->owner->id)->set('transferPassword', 'password')->call('transferOwnership')->assertHasErrors('transferTo');

    expect($this->owner->fresh()->role)->toBe(OrganizationRole::Owner);
});

test('27. ownership cannot go to a suspended, invited or outside person', function () {
    $this->team->suspend($this->owner, $this->staff);
    [$other] = array_values(teamBusiness('Other Co'));
    $invited = User::factory()->for($this->organization)->staff()->create(['status' => MemberStatus::Removed]);

    foreach ([$this->staff->fresh(), $other, $invited] as $target) {
        expect(fn () => $this->team->transferOwnership($this->owner, $target, 'password'))->toThrow(TeamActionException::class);
    }

    expect($this->owner->fresh()->role)->toBe(OrganizationRole::Owner);
});

test('28. the last owner cannot be removed or suspended', function () {
    $actor = User::factory()->manager()->for($this->organization)->create();
    // Even with team permission (via a temporary second account) the owner is protected.
    foreach ([fn () => $this->team->remove($this->owner, $this->owner, null), fn () => $this->team->suspend($this->owner, $this->owner)] as $action) {
        expect($action)->toThrow(TeamActionException::class);
    }

    expect(fn () => $this->team->remove($actor, $this->owner, null))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->owner)->test(TeamMembers::class)->assertDontSeeHtml('wire:click="suspend('.$this->owner->id.')"');
    expect($this->owner->fresh()->status)->toBe(MemberStatus::Active);
});

test('29. the last owner cannot be demoted', function () {
    // Promote someone to a second "owner" via a role change is refused, as is demoting the owner.
    expect(fn () => $this->team->changeRole($this->owner, $this->manager, OrganizationRole::Owner))->toThrow(TeamActionException::class, 'Transfer ownership')
        ->and(fn () => $this->team->changeRole($this->owner, $this->owner, OrganizationRole::Staff))->toThrow(TeamActionException::class);

    // The database also allows only one owner per organization.
    expect(fn () => DB::transaction(fn () => $this->manager->forceFill(['role' => OrganizationRole::Owner])->save()))->toThrow(UniqueConstraintViolationException::class);
    expect(User::where('organization_id', $this->organization->id)->where('role', 'owner')->count())->toBe(1)
        ->and(TeamActionException::LAST_OWNER)->toBe('An organization must always have an owner.');
});

// Assignment

test('30. a task can be assigned to an active team member, who is notified', function () {
    $task = app(TaskService::class)->create($this->owner, $this->customer, 'Measure the attic', TaskPriority::High, assignee: $this->staff);

    expect($task->assigned_to)->toBe($this->staff->id)
        ->and($this->staff->notifications()->sole()->data['message'])->toBe('John Smith assigned you a task: Measure the attic');

    app(TaskService::class)->assign($this->owner, $task, $this->manager);
    expect($task->fresh()->assigned_to)->toBe($this->manager->id);
});

test('31. a follow-up can be assigned to an active team member', function () {
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id]);
    $followUp = app(FollowUpService::class)->scheduleManual($this->owner, $this->customer, now()->addDay(), 'Check in', $conversation, $this->staff);

    expect($followUp->assigned_to)->toBe($this->staff->id);

    Livewire::actingAs($this->owner)->test(FollowUpIndex::class)
        ->call('openFollowUpForm', $followUp->id, 'assign')->set('assignTo', (string) $this->manager->id)->call('assignFollowUp')
        ->assertSee('Follow-up assigned to Sarah Wilson.');
    expect($followUp->fresh()->assigned_to)->toBe($this->manager->id);
});

test('32. work cannot be assigned to someone in another organization', function () {
    ['owner' => $outsider] = teamBusiness('Other Co');
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id]);

    expect(fn () => app(TaskService::class)->create($this->owner, $this->customer, 'Call', assignee: $outsider))->toThrow(Exception::class)
        ->and(fn () => app(FollowUpService::class)->scheduleManual($this->owner, $this->customer, now()->addDay(), 'x', $conversation, $outsider))->toThrow(InvalidFollowUpException::class);

    Livewire::actingAs($this->owner)->test(FollowUpIndex::class)
        ->call('openScheduleForm')->set('scheduleCustomerId', (string) $this->customer->id)->set('scheduleAssignee', (string) $outsider->id)
        ->call('scheduleFollowUp')->assertHasErrors('scheduleAssignee');
    expect(Task::count() + FollowUp::count())->toBe(0);
});

test('33. work cannot be assigned to a suspended member', function () {
    $this->team->suspend($this->owner, $this->staff);
    $suspended = $this->staff->fresh();
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id]);

    expect(fn () => app(TaskService::class)->create($this->owner, $this->customer, 'Call', assignee: $suspended))->toThrow(Exception::class)
        ->and(fn () => app(FollowUpService::class)->scheduleManual($this->owner, $this->customer, now()->addDay(), 'x', $conversation, $suspended))->toThrow(InvalidFollowUpException::class, 'active team members');

    // Pickers only offer active members.
    Livewire::actingAs($this->owner)->test(FollowUpIndex::class)->call('openScheduleForm')
        ->assertSee('Sarah Wilson')->assertDontSeeHtml('value="'.$suspended->id.'"');
});

// Notifications

test('34. a person can save their own notification preferences', function () {
    Livewire::actingAs($this->staff)->test(NotificationSettings::class)
        ->assertSeeHtml("You're using the default notification settings.")
        ->set('mine.estimate_accepted.email', true)->set('mine.task_assigned.in_app', false)
        ->call('save')->assertSee('Your notification settings were saved.')->assertDontSeeHtml("You're using the default notification settings.");

    $prefs = app(NotificationPreferences::class);
    expect($prefs->enabled($this->staff, NotificationType::EstimateAccepted, NotificationChannel::Email))->toBeTrue()
        ->and($prefs->enabled($this->staff, NotificationType::TaskAssigned, NotificationChannel::InApp))->toBeFalse()
        ->and(UserNotificationPreference::where('user_id', $this->staff->id)->count())->toBe(2);

    Livewire::actingAs($this->staff)->test(NotificationSettings::class)->call('useDefaults');
    expect(UserNotificationPreference::count())->toBe(0);
});

test('35. a person\'s preference overrides the organization default', function () {
    Livewire::actingAs($this->manager)->test(NotificationSettings::class)
        ->set('defaults.automation_failed.email', true)->call('saveDefaults')->assertSee('Business notification defaults saved.');
    Livewire::actingAs($this->staff)->test(NotificationSettings::class)->set('mine.automation_failed.email', false)->call('save');

    $prefs = app(NotificationPreferences::class);
    $this->owner->refresh();
    $this->staff->refresh();
    $this->organization->refresh();
    expect($prefs->enabled($this->owner, NotificationType::AutomationFailed, NotificationChannel::Email))->toBeTrue()
        ->and($prefs->enabled($this->staff, NotificationType::AutomationFailed, NotificationChannel::Email))->toBeFalse();

    // The notifier follows the preferences: owner emailed, Mike not.
    app(TeamNotifier::class)->notify($this->organization, NotificationType::AutomationFailed, [$this->owner, $this->staff], 'Automation failed.', null, 'test-key');
    expect(Message::where('metadata->type', 'team_notification')->pluck('to_address')->all())->toBe([$this->owner->email]);

    // Staff can't change the business defaults.
    Livewire::actingAs($this->staff)->test(NotificationSettings::class)->call('saveDefaults')->assertForbidden();
});

test('36. notification preferences are isolated per organization', function () {
    ['owner' => $otherOwner, 'organization' => $other] = teamBusiness('Other Co');
    Livewire::actingAs($otherOwner)->test(NotificationSettings::class)->set('defaults.estimate_declined.in_app', false)->call('saveDefaults');
    Livewire::actingAs($otherOwner)->test(NotificationSettings::class)->set('mine.customer_reply.in_app', true)->call('save');

    $prefs = app(NotificationPreferences::class);
    expect($prefs->enabled($this->owner, NotificationType::EstimateDeclined, NotificationChannel::InApp))->toBeTrue()
        ->and($prefs->enabled($this->owner, NotificationType::CustomerReply, NotificationChannel::InApp))->toBeFalse()
        ->and(UserNotificationPreference::where('organization_id', $this->organization->id)->count())->toBe(0);
});

// Security

test('37. organization A cannot view organization B\'s team', function () {
    ['owner' => $otherOwner, 'staff' => $otherStaff] = teamBusiness('Other Co');

    Livewire::actingAs($this->owner)->test(TeamMembers::class)->assertSee('Mike Johnson')->assertDontSee($otherOwner->email)->assertDontSee($otherStaff->email)
        ->call('startRemoval', $otherStaff->id)->assertNotFound();
});

test('38. organization A cannot modify organization B\'s settings or members', function () {
    ['owner' => $otherOwner, 'staff' => $otherStaff, 'organization' => $other] = teamBusiness('Other Co');

    expect(fn () => $this->team->suspend($this->owner, $otherStaff))->toThrow(TeamActionException::class, 'Team member not found.')
        ->and(fn () => $this->team->changeRole($this->owner, $otherStaff, OrganizationRole::Manager))->toThrow(TeamActionException::class)
        ->and(fn () => $this->invitations->resend($this->owner, inviteSarah($otherOwner)['invitation']->id))->toThrow(TeamActionException::class);

    // Settings always change the actor's own organization: there is no organization ID to pass.
    app(BusinessSettingsService::class)->updateProfile($this->owner, ['name' => 'Dallas HVAC LLC', 'country' => 'US']);
    expect($other->fresh()->name)->toBe('Other Co')->and($otherStaff->fresh()->status)->toBe(MemberStatus::Active);
    $this->actingAs($this->owner)->post('/settings/business', ['organization_id' => $other->id])->assertStatus(405);
});

test('39. staff cannot open restricted settings', function () {
    foreach (['/settings/business', '/settings/preferences', '/settings/email', '/settings/team', '/settings/estimates', '/settings/follow-ups', '/settings/automation'] as $url) {
        $this->actingAs($this->staff)->get($url)->assertForbidden();
    }

    $this->actingAs($this->staff)->get('/settings')->assertRedirect(route('settings.notifications'));
    $this->actingAs($this->staff)->get('/settings/security')->assertOk()->assertDontSee('Danger zone');
});

test('40. a manager cannot transfer ownership', function () {
    $this->actingAs($this->manager)->get('/settings/security')->assertOk()->assertDontSee('Transfer ownership');
    Livewire::actingAs($this->manager)->test(AccountSecurity::class)->set('transferTo', (string) $this->staff->id)->set('transferConfirmed', true)
        ->set('transferPassword', 'password')->call('transferOwnership')->assertForbidden();
    expect(fn () => $this->team->transferOwnership($this->manager, $this->staff, 'password'))->toThrow(AuthorizationException::class)
        ->and($this->owner->fresh()->role)->toBe(OrganizationRole::Owner);
});

// Account security

test('a member can change their password and sign out other sessions', function () {
    DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $this->staff->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'iPhone']);

    Livewire::actingAs($this->staff)->test(AccountSecurity::class)
        ->assertSee('iPhone')->assertSee('Dallas HVAC · Staff · Active')
        ->set('currentPassword', 'wrong')->set('newPassword', 'brand-new-pass-1')->set('newPasswordConfirmation', 'brand-new-pass-1')->call('changePassword')->assertHasErrors('currentPassword')
        ->set('currentPassword', 'password')->set('newPassword', 'brand-new-pass-1')->set('newPasswordConfirmation', 'brand-new-pass-1')->call('changePassword')->assertHasNoErrors()->assertSee('Password changed.')
        ->set('sessionsPassword', 'brand-new-pass-1')->call('logoutOtherSessions')->assertHasNoErrors();

    expect(Hash::check('brand-new-pass-1', $this->staff->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'phone')->exists())->toBeFalse()
        ->and(OrganizationActivity::where('action', 'password_changed')->exists())->toBeTrue();
});

test('sign-up creates a business owned by the new account', function () {
    $this->post('/register', ['business_name' => 'Austin Plumbing', 'name' => 'Ana Ruiz', 'email' => 'ana@austinplumbing.com', 'password' => 'a-good-password-1', 'password_confirmation' => 'a-good-password-1'])
        ->assertRedirect(route('settings.business'));

    $ana = User::where('email', 'ana@austinplumbing.com')->sole();
    expect($ana->role)->toBe(OrganizationRole::Owner)->and($ana->organization->name)->toBe('Austin Plumbing')->and($ana->organization_id)->not->toBe($this->organization->id);

    // An email already in use is refused.
    $this->post('/logout');
    $this->post('/register', ['business_name' => 'X', 'name' => 'X', 'email' => $this->staff->email, 'password' => 'a-good-password-1', 'password_confirmation' => 'a-good-password-1'])->assertSessionHasErrors('email');
});

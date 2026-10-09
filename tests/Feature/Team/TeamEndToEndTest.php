<?php

use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Jobs\SendEmailJob;
use App\Livewire\Estimates\EstimateForm;
use App\Livewire\Settings\AccountSecurity;
use App\Livewire\Settings\BusinessPreferences;
use App\Livewire\Settings\EstimateDefaults;
use App\Livewire\Settings\FollowUpDefaults;
use App\Livewire\Settings\TeamMembers;
use App\Livewire\Team\AcceptInvitation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\User;
use App\Services\Team\TeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    // Dallas HVAC, owner John Smith; Sarah and Mike are added per test.
    $this->owner = User::factory()->owner()->create(['name' => 'John Smith', 'email' => 'john@dallashvac.com']);
    $this->organization = $this->owner->organization;
    $this->organization->forceFill(['name' => 'Dallas HVAC'])->save();
    EmailConnection::factory()->verified()->default()->create(['organization_id' => $this->organization->id, 'domain' => 'dallashvac.com', 'sender_email' => 'hello@dallashvac.com', 'sender_name' => 'Dallas HVAC']);
    $this->customer = Customer::factory()->for($this->organization)->create(['name' => 'Pat Garcia', 'email' => 'pat@example.com']);
});

test('E2E 1: an invited manager joins and gets manager access only', function () {
    Queue::fake([SendEmailJob::class]);

    // Configure: timezone, currency, estimate validity, follow-up delay.
    Livewire::actingAs($this->owner)->test(BusinessPreferences::class)->set('timezone', 'America/Chicago')->set('currency', 'USD')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->owner)->test(EstimateDefaults::class)->set('validDays', '30')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->owner)->test(FollowUpDefaults::class)->set('delayDays', '3')->call('save')->assertHasNoErrors();
    $settings = $this->organization->fresh();
    expect($settings->timezone())->toBe('America/Chicago')->and($settings->currencyCode())->toBe('USD')
        ->and($settings->businessSettings()->estimateValidDays())->toBe(30)->and($settings->businessSettings()->followUpDelayDays())->toBe(3);

    // Invite Sarah Wilson as manager: the invitation email is queued.
    $team = Livewire::actingAs($this->owner)->test(TeamMembers::class)
        ->call('openInviteForm')->set('inviteName', 'Sarah Wilson')->set('inviteEmail', 'sarah@dallashvac.com')->set('inviteRole', 'manager')->call('invite')->assertHasNoErrors();
    $email = Message::where('to_address', 'sarah@dallashvac.com')->sole();
    Queue::assertPushed(SendEmailJob::class, fn ($job) => $job->messageId === $email->id);
    preg_match('~/invitations/([A-Za-z0-9]{64})~', $email->body_text, $m);

    // Sarah accepts and is an active manager.
    Auth::logout();
    Livewire::test(AcceptInvitation::class, ['token' => $m[1]])->set('password', 'sarah-pass-1234')->set('passwordConfirmation', 'sarah-pass-1234')->call('accept')->assertRedirect(route('dashboard'));
    $sarah = User::where('email', 'sarah@dallashvac.com')->sole();
    expect($sarah->role)->toBe(OrganizationRole::Manager)->and($sarah->status)->toBe(MemberStatus::Active);

    // Sarah can work with customers, estimates, follow-ups and automations…
    foreach (['/customers', "/customers/{$this->customer->id}", '/estimates', '/estimates/create', '/follow-ups', '/automations', '/automations/create'] as $url) {
        $this->actingAs($sarah)->get($url)->assertOk();
    }

    // …but not transfer ownership, manage the email provider, or remove the owner.
    expect($sarah->can('transfer-ownership'))->toBeFalse();
    Livewire::actingAs($sarah)->test(AccountSecurity::class)->set('transferTo', (string) $this->owner->id)->set('transferConfirmed', true)->set('transferPassword', 'sarah-pass-1234')->call('transferOwnership')->assertForbidden();
    $this->actingAs($sarah)->get('/settings/email')->assertForbidden();
    $this->actingAs($sarah)->get('/settings/team')->assertForbidden();
    expect(fn () => app(TeamService::class)->remove($sarah, $this->owner, null))->toThrow(AuthorizationException::class)
        ->and($this->owner->fresh()->status)->toBe(MemberStatus::Active);
});

test('E2E 2: staff can create estimates but not change the timezone or manage the team', function () {
    User::factory()->manager()->for($this->organization)->create(['name' => 'Sarah Wilson']);
    $staff = User::factory()->staff()->for($this->organization)->create(['name' => 'Mike Johnson']);

    // Change the business timezone → 403.
    $this->actingAs($staff)->get('/settings/preferences')->assertForbidden();
    Livewire::actingAs($staff)->test(BusinessPreferences::class)->assertForbidden();
    expect($this->organization->fresh()->timezone())->toBe('America/Chicago');

    // Create an estimate → allowed.
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($staff)->test(EstimateForm::class)
        ->set('title', 'Duct cleaning')->set('items.0.description', 'Duct cleaning')->set('items.0.unit_price', '400')
        ->call('save')->assertHasNoErrors();
    expect(Estimate::sole()->created_by)->toBe($staff->id);

    // Manage the team → denied.
    $this->actingAs($staff)->get('/settings/team')->assertForbidden();
    Livewire::actingAs($staff)->test(TeamMembers::class)->assertForbidden();
});

test('E2E 3: the estimate validity default pre-fills new estimates without overriding the user', function () {
    $this->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 10, 5)->setTime(9, 0));

    Livewire::actingAs($this->owner)->test(EstimateDefaults::class)->assertSet('validDays', '30')->set('validDays', '45')->call('save')->assertHasNoErrors();

    $form = Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->owner->fresh())->test(EstimateForm::class)
        ->assertSet('validUntil', '2026-11-19'); // 45 days

    // The person changes it to 60 days: kept.
    $form->set('validUntil', '2026-12-04')->set('title', 'AC Installation')->set('items.0.description', 'AC Installation')->set('items.0.unit_price', '2500')
        ->call('save')->assertHasNoErrors();

    expect(Estimate::sole()->valid_until->toDateString())->toBe('2026-12-04');
});

test('E2E 4: ownership moves from John to Sarah and there is always exactly one owner', function () {
    $sarah = User::factory()->manager()->for($this->organization)->create(['name' => 'Sarah Wilson']);

    Livewire::actingAs($this->owner)->test(AccountSecurity::class)
        ->set('transferTo', (string) $sarah->id)->set('myNewRole', 'manager')->set('transferPassword', 'password')->set('transferConfirmed', true)
        ->call('transferOwnership')->assertHasNoErrors();

    $john = $this->owner->fresh();
    expect($sarah->fresh()->role)->toBe(OrganizationRole::Owner)
        ->and($john->role)->toBe(OrganizationRole::Manager)
        ->and(User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Owner)->where('status', MemberStatus::Active)->count())->toBe(1);

    // John can't transfer ownership again (he isn't the owner any more).
    $this->actingAs($john)->get('/settings/security')->assertOk()->assertDontSee('Danger zone');
    expect(fn () => app(TeamService::class)->transferOwnership($john, $sarah, 'password'))->toThrow(AuthorizationException::class);

    // Sarah, the new owner, can give it back.
    app(TeamService::class)->transferOwnership($sarah->fresh(), $john, 'password');
    expect($john->fresh()->role)->toBe(OrganizationRole::Owner)->and($sarah->fresh()->role)->toBe(OrganizationRole::Manager)
        ->and(User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

<?php

namespace App\Livewire\Settings;

use App\Enums\OrganizationRole;
use App\Enums\Team\Permission;
use App\Exceptions\Team\TeamActionException;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\OrganizationActivity;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The signed-in person's own account: password, sessions, membership. The owner also finds
 * the danger zone (ownership transfer) here.
 */
#[Layout('components.layouts.app')]
#[Title('Account & Security')]
class AccountSecurity extends Component
{
    use SettingsPage;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $sessionsPassword = '';

    public string $transferTo = '';

    public string $myNewRole = 'manager';

    public bool $transferConfirmed = false;

    public string $transferPassword = '';

    public function mount(): void
    {
        $this->authorize('access-organization');
    }

    public function changePassword(): void
    {
        $this->authorize('access-organization');
        $this->resetErrorBag();

        try {
            $this->validate([
                'currentPassword' => ['required', 'current_password'],
                'newPassword' => ['required', 'string', Password::defaults(), 'same:newPasswordConfirmation', 'different:currentPassword'],
            ], ['currentPassword.current_password' => 'Your current password is incorrect.', 'newPassword.same' => 'The new passwords don\'t match.', 'newPassword.different' => 'Choose a password you haven\'t used here.'],
                ['currentPassword' => 'current password', 'newPassword' => 'new password']);
        } catch (ValidationException $e) {
            $this->reset(['currentPassword']);

            throw $e;
        }

        $user = $this->user();
        $user->forceFill(['password' => Hash::make($this->newPassword)])->save();
        OrganizationActivity::record($user->organization_id, 'password_changed', $user, [], $user);
        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $this->saved('Password changed.');
    }

    /**
     * Sign out everywhere except this browser (Laravel's database sessions + "remember me").
     */
    public function logoutOtherSessions(): void
    {
        $this->authorize('access-organization');
        $this->validate(['sessionsPassword' => ['required', 'current_password']], ['sessionsPassword.current_password' => 'Your password is incorrect.']);

        $user = $this->user();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        OrganizationActivity::record($user->organization_id, 'other_sessions_logged_out', $user, [], $user);
        $this->reset(['sessionsPassword']);
        $this->saved('Other sessions were signed out.');
    }

    public function transferOwnership(TeamService $team, TeamDirectory $directory): void
    {
        $this->authorize(Permission::TransferOwnership->value);
        $this->resetErrorBag();

        if (! $this->transferConfirmed) {
            $this->addError('transferConfirmed', 'Confirm that you understand what transferring ownership means.');

            return;
        }

        $target = $directory->activeMember($this->organization()->id, $this->transferTo);
        $role = OrganizationRole::tryFrom($this->myNewRole);

        if ($target === null || $target->id === $this->user()->id) {
            $this->addError('transferTo', 'Choose an active team member other than yourself.');

            return;
        }

        try {
            $team->transferOwnership($this->user(), $target, $this->transferPassword, $role ?? OrganizationRole::Manager);
        } catch (TeamActionException $e) {
            $this->addError(str_contains($e->getMessage(), 'password') ? 'transferPassword' : 'transferTo', $e->getMessage());
            $this->reset(['transferPassword']);

            return;
        }

        session()->flash('settings-status', "{$target->name} is now the owner. You are now a ".($role ?? OrganizationRole::Manager)->label().'.');
        $this->redirectRoute('settings.security', navigate: true);
    }

    /**
     * @return Collection<int, object>
     */
    private function sessions(): Collection
    {
        return DB::table('sessions')->where('user_id', $this->user()->id)->orderByDesc('last_activity')->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => (object) [
                'current' => $s->id === session()->getId(),
                'ip' => $s->ip_address,
                'agent' => Str::limit((string) $s->user_agent, 80),
                'last_active' => CarbonImmutable::createFromTimestamp($s->last_activity),
            ]);
    }

    public function render(TeamDirectory $directory)
    {
        if (session()->has('settings-status')) {
            $this->statusMessage = session('settings-status');
        }

        $user = $this->user();

        return view('livewire.settings.account-security', [
            'user' => $user,
            'organization' => $this->organization(),
            'sessions' => $this->sessions(),
            'candidates' => $user->hasPermission(Permission::TransferOwnership) ? $directory->assignableOptions($user->organization_id)->except([$user->id]) : collect(),
            'roles' => OrganizationRole::assignable(),
        ]);
    }
}

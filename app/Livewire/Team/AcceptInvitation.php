<?php

namespace App\Livewire\Team;

use App\Exceptions\Team\TeamActionException;
use App\Services\Team\InvitationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The page behind an invitation link: shows the business and role, and creates the account.
 * The token is the only credential; the organization and role come from the invitation record.
 */
#[Layout('components.layouts.guest')]
#[Title('Join your team')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token, InvitationService $invitations): void
    {
        $this->token = $token;
        $this->name = $invitations->find($token)?->name ?? '';
    }

    public function accept(InvitationService $invitations): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', Password::defaults(), 'same:passwordConfirmation'],
        ], ['password.same' => 'The passwords don\'t match.']);

        try {
            $user = $invitations->accept($this->token, $this->name, $this->password);
        } catch (TeamActionException $e) {
            $this->addError('invitation', $e->getMessage());

            return;
        }

        Auth::guard('web')->logout();
        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('dashboard');
    }

    public function render(InvitationService $invitations)
    {
        $invitation = $invitations->find($this->token);

        return view('livewire.team.accept-invitation', [
            'invitation' => $invitation,
            'state' => match (true) {
                $invitation === null, $invitation->revoked_at !== null => 'invalid',
                $invitation->accepted_at !== null => 'used',
                $invitation->isExpired() => 'expired',
                default => 'open',
            },
            'signedInAs' => Auth::user(),
        ]);
    }
}

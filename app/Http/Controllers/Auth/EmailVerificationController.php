<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirming the email address after sign-up: the "check your email" page, the link in the email, and "send it again".
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect('/')
            : view('auth.verify-email', ['email' => $request->user()->email]);
    }

    /**
     * The link from the email. It is signed and carries the account's ID and a hash of its address, so it works
     * in any browser, including one where nobody is signed in.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $signedIn = $request->user('web')?->is($user);

        return redirect($signedIn ? '/' : route('login'))
            ->with('status', 'Thanks, your email address is confirmed.'.($signedIn ? '' : ' You can sign in now.'));
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect('/');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'We sent a new link to '.$request->user()->email.'.');
    }
}

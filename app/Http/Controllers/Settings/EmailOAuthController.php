<?php

namespace App\Http\Controllers\Settings;

use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use App\Http\Controllers\Controller;
use App\Services\Email\OAuth\OAuthConnectionService;
use App\Services\Email\OAuth\OAuthMailClient;
use App\Services\Email\OAuth\OAuthStateStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * "Connect Google" and "Connect Microsoft". The business is sent to the provider to approve access; the provider
 * sends them back with a code, which is checked against the one-time state before anything is saved.
 */
class EmailOAuthController extends Controller
{
    public function redirect(string $provider, OAuthStateStore $states, OAuthMailClient $client): RedirectResponse
    {
        $provider = $this->provider($provider);
        $user = Auth::user();

        try {
            $pending = $states->begin($provider, $user->organization_id, $user->id);
            $url = $client->authorizeUrl($provider, $pending['state'], $pending['challenge'], $this->callbackUrl($provider));
        } catch (EmailProviderException $e) {
            return redirect()->route('settings.email')->with('error', $e->userMessage());
        }

        return redirect()->away($url);
    }

    public function callback(string $provider, Request $request, OAuthStateStore $states, OAuthConnectionService $connections): RedirectResponse
    {
        $provider = $this->provider($provider);
        $user = Auth::user();

        if ($request->query('error') !== null) {
            // The business said no, or the provider stopped the sign-in. Nothing was saved.
            return redirect()->route('settings.email')->with('error', 'Connection cancelled. Nothing was changed.');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', 'max:128'],
        ]);

        try {
            $verifier = $states->consume($provider, $validated['state'], $user->organization_id, $user->id);
            $connection = $connections->connect($user, $user->organization, $provider, $validated['code'], $verifier, $this->callbackUrl($provider));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('settings.email')->with('error', $e->getMessage());
        } catch (EmailProviderException $e) {
            return redirect()->route('settings.email')->with('error', $e->userMessage());
        } catch (ValidationException $e) {
            return redirect()->route('settings.email')->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('settings.email')->with('status', 'Connected '.$connection->sender_email.'.');
    }

    private function provider(string $value): EmailProvider
    {
        $provider = EmailProvider::tryFrom($value);

        abort_unless($provider === EmailProvider::Gmail || $provider === EmailProvider::Microsoft, 404);

        return $provider;
    }

    private function callbackUrl(EmailProvider $provider): string
    {
        return route('settings.email.oauth.callback', ['provider' => $provider->value]);
    }
}

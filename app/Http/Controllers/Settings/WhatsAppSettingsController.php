<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\WhatsApp\WhatsAppException;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppConfiguration;
use App\Services\WhatsApp\WhatsAppSignupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The WhatsApp card in Settings. The page shows the connection status and, only when WhatsApp is fully
 * configured, the button that starts Meta's Embedded Signup.
 */
class WhatsAppSettingsController extends Controller
{
    public function show(WhatsAppConfiguration $configuration): View
    {
        $connection = WhatsAppConnection::query()->where('organization_id', Auth::user()->organization_id)->first();

        return view('settings.whatsapp', [
            'connection' => $connection,
            'signupReady' => $configuration->signupReady(),
            'appId' => config('whatsapp.app_id'),
            'signupConfigId' => config('whatsapp.signup_config_id'),
            'graphVersion' => config('whatsapp.graph.version'),
        ]);
    }

    public function connect(Request $request, WhatsAppConfiguration $configuration, WhatsAppSignupService $signup): RedirectResponse
    {
        abort_unless($configuration->signupReady(), 404);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'regex:/^\d{1,32}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{1,32}$/'],
        ]);

        try {
            $connection = $signup->connect(Auth::user()->organization, $validated['code'], $validated['waba_id'], $validated['phone_number_id']);
        } catch (WhatsAppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('settings.whatsapp')->with('status', 'WhatsApp connected: '.$connection->display_phone_number.'.');
    }
}

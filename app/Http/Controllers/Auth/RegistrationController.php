<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Sign up: a new business and its owner, in one transaction. Everyone else joins by invitation.
 */
class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], [], ['business_name' => 'business name', 'name' => 'your name']);

        $user = DB::transaction(function () use ($data) {
            $organization = new Organization;
            $organization->forceFill(['name' => trim($data['business_name']), 'timezone' => config('follow_ups.default_timezone')])->save();

            $user = new User;
            $user->forceFill([
                'organization_id' => $organization->id,
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => OrganizationRole::Owner,
                'status' => MemberStatus::Active,
            ])->save();

            OrganizationActivity::record($organization, 'business_created', $user);
            app(BillingService::class)->startSignupTrial($organization);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('settings.business')->with('settings-status', 'Welcome! Start by checking your business details.');
    }
}

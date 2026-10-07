<?php

use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Estimates\PublicEstimateController;
use App\Http\Controllers\Settings\LogoController;
use App\Http\Controllers\Webhooks\InboundEmailWebhookController;
use App\Livewire\Automations\AutomationForm;
use App\Livewire\Automations\AutomationIndex;
use App\Livewire\Automations\AutomationLogs;
use App\Livewire\Automations\ShowAutomation;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Estimates\EstimateForm;
use App\Livewire\Estimates\EstimateIndex;
use App\Livewire\Estimates\ShowEstimate;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\AccountSecurity;
use App\Livewire\Settings\AutomationDefaults;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\BusinessPreferences;
use App\Livewire\Settings\BusinessProfile;
use App\Livewire\Settings\EmailSettings;
use App\Livewire\Settings\EstimateDefaults;
use App\Livewire\Settings\FollowUpDefaults;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\RolesPermissions;
use App\Livewire\Settings\TeamMembers;
use App\Livewire\Team\AcceptInvitation;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Support\Settings\SettingsNavigation;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', Dashboard::class)->middleware('can:access-organization')->name('dashboard');
    Route::get('/follow-ups', FollowUpIndex::class)->middleware('can:viewAny,'.FollowUp::class)->name('follow-ups.index');
    // Customer IDs are looked up inside the user's organization by each component.
    Route::middleware('can:access-organization')->group(function () {
        Route::get('/customers', CustomerIndex::class)->name('customers.index');
        Route::get('/customers/create', CustomerForm::class)->middleware('can:create,'.Customer::class)->name('customers.create');
        Route::get('/customers/{customerId}', ShowCustomer::class)->whereNumber('customerId')->name('customers.show');
        Route::get('/customers/{customerId}/edit', CustomerForm::class)->whereNumber('customerId')->name('customers.edit');
    });
});

// Sign in / out (plain Laravel session authentication).
Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:login');
});
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

// Team invitation links: the unguessable token is the only credential.
Route::get('/invitations/{token}', AcceptInvitation::class)->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:invitations')->name('invitations.show');

// Business logos (shown on estimates): only a business's current logo file is served.
Route::get('/logos/{file}', LogoController::class)->where('file', '[a-z0-9]{40}\.(png|jpg|webp)')->name('logos.show');

// Settings: each page is limited to the roles allowed to use it (and re-checked by its component and services).
Route::middleware(['auth', 'can:access-organization'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', fn () => redirect()->route(SettingsNavigation::home(auth()->user())))->name('index');

    Route::get('/business', BusinessProfile::class)->middleware('can:manage-business-profile')->name('business');
    Route::get('/preferences', BusinessPreferences::class)->middleware('can:manage-business-profile')->name('preferences');

    Route::get('/email', EmailSettings::class)
        ->middleware('can:viewAny,'.EmailConnection::class)
        ->name('email');
    Route::get('/notifications', NotificationSettings::class)->name('notifications');

    Route::get('/team', TeamMembers::class)->middleware('can:manage-team')->name('team');
    Route::get('/roles', RolesPermissions::class)->name('roles');

    Route::get('/estimates', EstimateDefaults::class)->middleware('can:manage-business-defaults')->name('estimates');
    Route::get('/follow-ups', FollowUpDefaults::class)->middleware('can:manage-business-defaults')->name('follow-ups');
    Route::get('/automation', AutomationDefaults::class)->middleware('can:manage-business-defaults')->name('automation');

    Route::get('/security', AccountSecurity::class)->name('security');
    Route::get('/billing', BillingOverview::class)->middleware('can:manage-billing')->name('billing');

    // Automations moved to /automations (Task 12); old links keep working.
    Route::get('/automations/{path?}', fn (?string $path = null) => redirect('/automations'.($path ? '/'.str_replace('/runs', '/logs', $path) : ''), 301))->where('path', '.*')->name('automations.legacy');
});

// Conversations live at /conversations; the route names stay "inbox.*" for existing links.
Route::middleware(['auth', 'can:access-organization'])->group(function () {
    Route::get('/conversations', ConversationList::class)->name('inbox.index');
    Route::get('/conversations/{conversationId}', ShowConversation::class)->whereNumber('conversationId')->name('inbox.show');
});

// Automations (admins): IDs are looked up inside the user's organization by each component.
Route::middleware(['auth', 'can:viewAny,'.Automation::class])->prefix('automations')->name('automations.')->group(function () {
    Route::get('/', AutomationIndex::class)->name('index');
    Route::get('/create', AutomationForm::class)->name('create');
    Route::get('/{automationId}', ShowAutomation::class)->whereNumber('automationId')->name('show');
    Route::get('/{automationId}/edit', AutomationForm::class)->whereNumber('automationId')->name('edit');
    Route::get('/{automationId}/logs', AutomationLogs::class)->whereNumber('automationId')->name('logs');
    Route::get('/{automationId}/logs/{runId}', AutomationLogs::class)->whereNumber(['automationId', 'runId'])->name('logs.show');
});

// Estimates: IDs are looked up inside the user's organization by each component.
Route::middleware(['auth', 'can:access-organization'])->prefix('estimates')->name('estimates.')->group(function () {
    Route::get('/', EstimateIndex::class)->name('index');
    Route::get('/create', EstimateForm::class)->middleware('can:create,'.Estimate::class)->name('create');
    Route::get('/{estimateId}', ShowEstimate::class)->whereNumber('estimateId')->name('show');
    Route::get('/{estimateId}/edit', EstimateForm::class)->whereNumber('estimateId')->name('edit');
});

// The customer's estimate page: no account; the unguessable link is the only credential.
Route::prefix('estimate/view/{token}')->name('estimates.public.')->where(['token' => '[A-Za-z0-9]{48}'])->middleware('throttle:public-estimates')->group(function () {
    Route::get('/', [PublicEstimateController::class, 'show'])->name('show');
    Route::post('/accept', [PublicEstimateController::class, 'accept'])->name('accept');
    Route::post('/decline', [PublicEstimateController::class, 'decline'])->name('decline');
});

// Old addresses (e.g. links stored in notifications) keep working.
Route::redirect('/inbox', '/conversations', 301);
Route::get('/inbox/{conversationId}', fn (int $conversationId) => redirect()->route('inbox.show', $conversationId, 301))->whereNumber('conversationId');

// Called by email providers: authenticated by the provider handler, not by user sessions.
Route::post('/webhooks/email/inbound/{provider}', InboundEmailWebhookController::class)
    ->whereAlpha('provider')
    ->middleware('throttle:email-webhooks')
    ->name('webhooks.email.inbound');

<?php

use App\Http\Controllers\Estimates\PublicEstimateController;
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
use App\Livewire\Settings\BusinessSettings;
use App\Livewire\Settings\EmailSettings;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\FollowUp;
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

Route::middleware('auth')->prefix('settings')->name('settings.')->group(function () {
    Route::get('/business', BusinessSettings::class)
        ->middleware('can:create,'.Automation::class)
        ->name('business');

    Route::get('/email', EmailSettings::class)
        ->middleware('can:viewAny,'.EmailConnection::class)
        ->name('email');

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

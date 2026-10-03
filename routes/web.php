<?php

use App\Http\Controllers\Webhooks\InboundEmailWebhookController;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\Dashboard;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\Automations\AutomationEditor;
use App\Livewire\Settings\Automations\AutomationIndex;
use App\Livewire\Settings\Automations\AutomationRunLog;
use App\Livewire\Settings\BusinessSettings;
use App\Livewire\Settings\EmailSettings;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\EmailConnection;
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

    // IDs are looked up inside the user's organization by each component, never via implicit binding.
    Route::middleware('can:viewAny,'.Automation::class)->prefix('automations')->name('automations.')->group(function () {
        Route::get('/', AutomationIndex::class)->name('index');
        Route::get('/create', AutomationEditor::class)->name('create');
        Route::get('/{automationId}/edit', AutomationEditor::class)->whereNumber('automationId')->name('edit');
        Route::get('/{automationId}/runs', AutomationRunLog::class)->whereNumber('automationId')->name('runs');
        Route::get('/{automationId}/runs/{runId}', AutomationRunLog::class)->whereNumber(['automationId', 'runId'])->name('runs.show');
    });
});

// Conversations live at /conversations; the route names stay "inbox.*" for existing links.
Route::middleware(['auth', 'can:access-organization'])->group(function () {
    Route::get('/conversations', ConversationList::class)->name('inbox.index');
    Route::get('/conversations/{conversationId}', ShowConversation::class)->whereNumber('conversationId')->name('inbox.show');
});

// Old addresses (e.g. links stored in notifications) keep working.
Route::redirect('/inbox', '/conversations', 301);
Route::get('/inbox/{conversationId}', fn (int $conversationId) => redirect()->route('inbox.show', $conversationId, 301))->whereNumber('conversationId');

// Called by email providers: authenticated by the provider handler, not by user sessions.
Route::post('/webhooks/email/inbound/{provider}', InboundEmailWebhookController::class)
    ->whereAlpha('provider')
    ->middleware('throttle:email-webhooks')
    ->name('webhooks.email.inbound');

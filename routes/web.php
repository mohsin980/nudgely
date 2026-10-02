<?php

use App\Http\Controllers\Webhooks\InboundEmailWebhookController;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\EmailSettings;
use App\Models\EmailConnection;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->prefix('settings')->name('settings.')->group(function () {
    Route::get('/email', EmailSettings::class)
        ->middleware('can:viewAny,'.EmailConnection::class)
        ->name('email');
});

Route::middleware('auth')->prefix('inbox')->name('inbox.')->group(function () {
    Route::get('/', ConversationList::class)->name('index');
    Route::get('/{conversationId}', ShowConversation::class)->whereNumber('conversationId')->name('show');
});

// Called by email providers: authenticated by the provider handler, not by user sessions.
Route::post('/webhooks/email/inbound/{provider}', InboundEmailWebhookController::class)
    ->whereAlpha('provider')
    ->middleware('throttle:email-webhooks')
    ->name('webhooks.email.inbound');

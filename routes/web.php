<?php

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

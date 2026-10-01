<?php

namespace App\Providers;

use App\Contracts\Email\EmailProviderInterface;
use App\Services\Email\EmailProviderManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmailProviderManager::class);
        $this->app->bind(EmailProviderInterface::class, fn ($app) => $app->make(EmailProviderManager::class)->driver());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

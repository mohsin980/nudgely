<?php

namespace App\Providers;

use App\Contracts\Email\EmailProviderInterface;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->singleton(InboundEmailProviderManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('email-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}

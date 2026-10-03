<?php

namespace App\Providers;

use App\Contracts\Email\EmailProviderInterface;
use App\Models\User;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Automation\AutomationExecutionScope;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(ReplyClassifierManager::class);
        // Per job / request, so a chain depth never leaks into unrelated work.
        $this->app->scoped(AutomationExecutionScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pages that show an organization's data require the user to belong to one.
        Gate::define('access-organization', fn (User $user) => $user->organization_id !== null && $user->organization()->exists());

        RateLimiter::for('email-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}

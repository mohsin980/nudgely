<?php

namespace App\Providers;

use App\Billing\PlanCatalog;
use App\Contracts\Email\EmailProviderInterface;
use App\Enums\Team\Permission;
use App\Models\User;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Automation\AutomationExecutionScope;
use App\Services\Billing\BillingProviderManager;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $this->app->singleton(BillingProviderManager::class);
        // Plans are read from config/billing.php once and validated (a bad plan fails loudly).
        $this->app->singleton(PlanCatalog::class, fn ($app) => new PlanCatalog($app['config']->get('billing', [])));
        // Per job / request, so a chain depth never leaks into unrelated work.
        $this->app->scoped(AutomationExecutionScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production never shows stack traces, whatever APP_DEBUG says (a leaked .env must not leak internals).
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
            Log::critical('APP_DEBUG was enabled in production; it has been forced off. Fix the environment.');
        }

        // Suspended and removed people can do nothing, whatever their role or the policy says.
        Gate::before(fn (User $user) => $user->isActiveMember() ? null : false);

        // Pages that show an organization's data require the user to be an active member of one.
        Gate::define('access-organization', fn (User $user) => $user->isActiveMember() && $user->organization()->exists());

        // Role permissions (OrganizationRole::permissions()) as Gates: can:manage-team, @can('manage-email'), …
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }

        // First-run setup belongs to the business owner only; invited members are never put through it.
        Gate::define('manage-onboarding', fn (User $user) => $user->isOwner());

        RateLimiter::for('email-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        // Customer estimate links: generous for people, slow for anyone guessing tokens.
        RateLimiter::for('public-estimates', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // Anything that makes the payment provider do work for a business.
        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}

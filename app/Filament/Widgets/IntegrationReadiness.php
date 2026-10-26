<?php

namespace App\Filament\Widgets;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermissionForWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Whether each integration has its credentials set on this server. Shows presence only, never a key or token, and
 * it does not contact the provider: "Configured" means ready to use, not that a live connection test passed.
 */
class IntegrationReadiness extends StatsOverviewWidget
{
    use RequiresPlatformPermissionForWidget;

    protected static ?int $sort = 15;

    protected ?string $heading = 'Integration readiness';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewDashboard;
    }

    protected function getStats(): array
    {
        return [
            $this->stat('Email sending (Postmark)', filled(config('email.providers.postmark.server_token'))),
            $this->stat('Connect Google', filled(config('email.oauth.google.client_id'))),
            $this->stat('Connect Microsoft', filled(config('email.oauth.microsoft.client_id'))),
            $this->stat('Stripe billing', filled(config('services.stripe.secret')) && filled(config('services.stripe.webhook_secret'))),
        ];
    }

    private function stat(string $label, bool $configured): Stat
    {
        return Stat::make($label, $configured ? 'Configured' : 'Not configured')
            ->description($configured ? 'Credentials are set on this server' : 'Set the credentials in the server environment')
            ->color($configured ? 'success' : 'gray');
    }
}

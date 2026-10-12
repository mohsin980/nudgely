<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admin panel
    |--------------------------------------------------------------------------
    |
    | The panel lives at /admin (see App\Providers\Filament\AdminPanelProvider). Who may enter it is stored in
    | the platform_admins table and managed with `php artisan platform-admin:grant {email}`.
    |
    */

    'brand' => env('ADMIN_PANEL_BRAND', 'QuoteFlow AI'),

    // How long audit events are kept (days). Older events are removed by `platform-audit:prune` (scheduled daily).
    'audit_retention_days' => (int) env('ADMIN_AUDIT_RETENTION_DAYS', 730),

    // Log channel for refused admin access (null = the application's default channel).
    'audit_channel' => env('ADMIN_AUDIT_LOG_CHANNEL'),

];

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

];

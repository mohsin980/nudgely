<?php

namespace Tests\Support\Admin;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use Filament\Pages\Page;

class RolesFixturePage extends Page
{
    use RequiresPlatformPermission;

    protected string $view = 'admin-test::stub';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ManageRoles;
    }
}

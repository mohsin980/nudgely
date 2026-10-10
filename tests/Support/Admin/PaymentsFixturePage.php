<?php

namespace Tests\Support\Admin;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use Filament\Actions\Action;
use Filament\Pages\Page;

/** A billing-only page with a destructive action that needs a stronger permission than viewing. */
class PaymentsFixturePage extends Page
{
    use RequiresPlatformPermission;

    public static bool $ran = false;

    protected string $view = 'admin-test::stub';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewPayments;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refund')
                ->authorize(fn () => auth()->user()?->can(PlatformPermission::ManagePayments->value) ?? false)
                ->action(fn () => static::$ran = true),
        ];
    }
}

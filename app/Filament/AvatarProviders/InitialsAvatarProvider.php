<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials drawn locally as an inline SVG. Filament's default provider loads an image from a third-party
 * site (ui-avatars.com) and would send each administrator's name there.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $part) => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $part) ?? '', 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#7c3aed"/>'
            .'<text x="32" y="42" font-family="Arial, Helvetica, sans-serif" font-size="26" font-weight="700" fill="#ffffff" text-anchor="middle">'
            .e(mb_strtoupper($initials))
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

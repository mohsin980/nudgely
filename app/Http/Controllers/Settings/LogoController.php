<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a business logo by its random file name (shown on estimates, so no sign-in).
 * Only files recorded as a business's current logo are served, always as an image.
 */
class LogoController extends Controller
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __invoke(string $file): StreamedResponse
    {
        $path = 'logos/'.$file;
        $disk = Storage::disk(config('team.logo_disk', 'local'));

        abort_unless(Organization::query()->where('logo_path', $path)->exists() && $disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => self::TYPES[pathinfo($file, PATHINFO_EXTENSION)],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}

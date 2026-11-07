<?php

namespace App\Http\Controllers;

use App\Enums\MessageChannel;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppMediaStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a customer's WhatsApp photo, voice note, video or document to the business's own team only. Images, audio
 * and video play in the page; anything else downloads, and nothing is ever sniffed into another type.
 */
class WhatsAppMediaController
{
    public function __invoke(Message $message, WhatsAppMediaStore $media): StreamedResponse
    {
        abort_unless($message->channel === MessageChannel::WhatsApp, 404);
        Gate::authorize('view', $message->conversation ?? abort(404));

        $meta = $message->metadata ?? [];
        $path = (string) ($meta['media_path'] ?? '');
        abort_if($path === '' || ! Storage::disk('local')->exists($path), 404);

        $mime = (string) ($meta['media_mime'] ?? 'application/octet-stream');
        $inline = $media->isInline($mime);
        $name = (string) ($meta['media_name'] ?? basename($path));

        return Storage::disk('local')->response($path, $name, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}

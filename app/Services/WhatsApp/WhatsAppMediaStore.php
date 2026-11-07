<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Photos, voice notes, videos and documents customers send on WhatsApp. Meta only keeps them for about 30 days, so
 * each one is downloaded when it arrives and stored on the private disk (never publicly reachable; served only to
 * the business's own team by WhatsAppMediaController), then deleted after services.whatsapp.media_keep_days.
 */
class WhatsAppMediaStore
{
    public const TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

    /** What a browser may show inline; anything else is offered as a download. */
    public const INLINE = [
        'image/jpeg', 'image/png', 'image/webp',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr',
        'video/mp4', 'video/3gpp',
    ];

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(private readonly WhatsAppClient $client) {}

    /**
     * The media details from an incoming WhatsApp message, for the message's metadata.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function describe(array $data): array
    {
        $type = (string) ($data['type'] ?? '');
        $media = (array) ($data[$type] ?? []);

        if (! in_array($type, self::TYPES, true) || blank($media['id'] ?? null)) {
            return [];
        }

        return array_filter([
            'media_id' => mb_substr((string) $media['id'], 0, 100),
            'media_mime' => mb_substr(strtolower(trim(explode(';', (string) ($media['mime_type'] ?? ''))[0])), 0, 100),
            'media_name' => isset($media['filename']) ? mb_substr(basename((string) $media['filename']), 0, 150) : null,
            'media_voice' => ! empty($media['voice']) ? '1' : null,
        ]);
    }

    /**
     * Download the message's media from Meta and keep it. Returns false when there is nothing to download, the file
     * is too large, or it already exists.
     *
     * @throws RuntimeException when Meta can't be reached (worth retrying)
     */
    public function download(Message $message): bool
    {
        $meta = $message->metadata ?? [];

        if ($message->direction !== MessageDirection::Inbound || $message->channel !== MessageChannel::WhatsApp
            || blank($meta['media_id'] ?? null) || filled($meta['media_path'] ?? null)) {
            return false;
        }

        $connection = WhatsAppConnection::query()->where('organization_id', $message->organization_id)->first();

        if ($connection === null || ! $connection->isActive()) {
            return false;
        }

        $max = (int) config('services.whatsapp.media_max_mb', 25) * 1024 * 1024;

        try {
            $info = $this->client->graph($connection)->get((string) $meta['media_id']);

            if (! $info->successful() || ! is_string($url = $info->json('url'))) {
                return $this->note($message, 'Meta no longer has this file.');
            }

            if ((int) $info->json('file_size') > $max) {
                return $this->note($message, 'The file is larger than '.config('services.whatsapp.media_max_mb').' MB, so it was not saved.');
            }

            // The download link is Meta's own and needs the business's token.
            $file = Http::withToken((string) $connection->access_token)->timeout(60)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta could not be reached to download WhatsApp media.');
        }

        if (! $file->successful() || $file->body() === '' || strlen($file->body()) > $max) {
            return $this->note($message, 'The file could not be downloaded from WhatsApp.');
        }

        $mime = strtolower(trim(explode(';', (string) ($info->json('mime_type') ?: ($meta['media_mime'] ?? 'application/octet-stream')))[0]));
        $path = 'whatsapp-media/'.$message->organization_id.'/'.now()->format('Y/m').'/'.$message->id.'-'.Str::random(16).'.'.(self::EXTENSIONS[$mime] ?? 'bin');
        Storage::disk('local')->put($path, $file->body());

        $message->forceFill(['metadata' => array_merge($meta, [
            'media_path' => $path,
            'media_mime' => $mime,
            'media_size' => strlen($file->body()),
            'media_error' => null,
        ])])->save();

        Log::info('WhatsApp media saved.', ['organization_id' => $message->organization_id, 'message_id' => $message->id, 'bytes' => strlen($file->body())]);

        return true;
    }

    public function isInline(?string $mime): bool
    {
        return in_array($mime, self::INLINE, true);
    }

    /**
     * Delete files older than services.whatsapp.media_keep_days. The message keeps its text and a note.
     *
     * @return int how many files were deleted
     */
    public function prune(): int
    {
        $before = now()->subDays((int) config('services.whatsapp.media_keep_days', 180));
        $deleted = 0;

        Message::query()->where('channel', MessageChannel::WhatsApp)->where('direction', MessageDirection::Inbound)
            ->where('created_at', '<', $before)->whereNotNull('metadata->media_path')
            ->chunkById(200, function ($messages) use (&$deleted) {
                foreach ($messages as $message) {
                    $meta = $message->metadata ?? [];
                    Storage::disk('local')->delete((string) $meta['media_path']);
                    $message->forceFill(['metadata' => array_merge($meta, ['media_path' => null, 'media_error' => 'Deleted after '.config('services.whatsapp.media_keep_days').' days.'])])->save();
                    $deleted++;
                }
            });

        return $deleted;
    }

    private function note(Message $message, string $error): bool
    {
        $message->forceFill(['metadata' => array_merge($message->metadata ?? [], ['media_error' => $error])])->save();

        return false;
    }
}

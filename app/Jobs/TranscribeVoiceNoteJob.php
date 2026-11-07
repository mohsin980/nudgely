<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AI\VoiceTranscriber;
use App\Services\WhatsApp\WhatsAppInboundProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribes a saved voice note into the message's text, then lets it be sorted and answered like a typed message.
 * Whatever happens, the message is answered once: with its transcript, or as "[Voice message]".
 */
class TranscribeVoiceNoteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $messageId) {}

    public function handle(VoiceTranscriber $transcriber): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            return;
        }

        $meta = $message->metadata ?? [];
        $audio = filled($meta['media_path'] ?? null) ? Storage::disk('local')->get((string) $meta['media_path']) : null;
        $result = $audio === null ? null : $transcriber->transcribe($audio, (string) ($meta['media_mime'] ?? 'audio/ogg'));

        if ($result !== null) {
            $message->forceFill([
                'body_text' => $result['text'],
                'metadata' => array_merge($meta, ['transcribed_by' => $result['provider'], 'transcribed_at' => now()->toIso8601String()]),
            ])->save();
        } else {
            $message->forceFill(['metadata' => array_merge($meta, ['transcript_error' => 'Could not be transcribed.'])])->save();
            Log::info('Voice note left untranscribed.', ['organization_id' => $message->organization_id, 'message_id' => $message->id]);
        }

        WhatsAppInboundProcessor::answer($message);
    }

    public function failed(): void
    {
        if (($message = Message::query()->find($this->messageId)) !== null) {
            WhatsAppInboundProcessor::answer($message);
        }
    }
}

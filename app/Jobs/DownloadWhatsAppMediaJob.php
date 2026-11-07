<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\WhatsApp\WhatsAppInboundProcessor;
use App\Services\WhatsApp\WhatsAppMediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Saves the photo, voice note, video or document a customer sent on WhatsApp. A voice note is then transcribed;
 * if it can't be downloaded, it is sorted and answered as it is ("[Voice message]").
 */
class DownloadWhatsAppMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public readonly int $messageId) {}

    public function handle(WhatsAppMediaStore $media): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            return;
        }

        $saved = $media->download($message);

        if (WhatsAppInboundProcessor::awaitsTranscript($message)) {
            $saved || filled($message->fresh()->metadata['media_path'] ?? null)
                ? TranscribeVoiceNoteJob::dispatch($message->id)
                : WhatsAppInboundProcessor::answer($message);
        }
    }

    public function failed(): void
    {
        // Meta couldn't be reached after every retry: a voice note is still answered, as "[Voice message]".
        $message = Message::query()->find($this->messageId);

        if ($message !== null && WhatsAppInboundProcessor::awaitsTranscript($message)) {
            WhatsAppInboundProcessor::answer($message);
        }
    }
}

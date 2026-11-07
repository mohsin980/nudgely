<?php

namespace App\Jobs;

use App\Mail\AiChatLogMail;
use App\Services\AI\AiChatLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails one full AI chat log file to the platform's log address, then deletes it. If no address is set, the file
 * is kept so nothing is lost; it is sent once an address is added and "Email now" is pressed.
 */
class EmailAiChatLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $file) {}

    public function handle(AiChatLog $log): void
    {
        $path = $log->path(basename($this->file));

        if (! is_file($path)) {
            return; // already sent
        }

        $email = $log->settings()['email'];

        if ($email === null) {
            Log::warning('AI chat log is full but no email address is set; the file is kept.', ['file' => basename($path)]);

            return;
        }

        Mail::to($email)->send(new AiChatLogMail($path));
        @unlink($path);

        Log::info('AI chat log emailed.', ['file' => basename($path)]);
    }
}

<?php

namespace App\Jobs;

use App\Services\Assistant\WhatsAppAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Lets the AI assistant answer one customer WhatsApp message. Dispatched a few seconds after the message, so a
 * customer who sends several messages in a row gets one answer to all of them (only the latest message's job
 * replies). One conversation is answered by one worker at a time.
 */
class AssistantReplyJob implements ShouldQueue
{
    use Queueable;

    public const DELAY_SECONDS = 4;

    public int $tries = 3;

    public int $timeout = 150;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public readonly int $messageId, public readonly int $conversationId) {}

    public function handle(WhatsAppAssistant $assistant): void
    {
        $lock = Cache::lock('assistant-conversation:'.$this->conversationId, $this->timeout);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $assistant->respond($this->messageId);
        } finally {
            $lock->release();
        }
    }
}

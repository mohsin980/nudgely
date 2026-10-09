<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Email\EmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one queued outbound message.
 *
 * Unique per message, and EmailService only sends a message it can atomically claim from
 * "queued", so a duplicate dispatch or a retry after success never emails the customer twice.
 */
class SendEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Only transient provider failures are rethrown; permanent ones are recorded and end the job.
     */
    public int $tries = 5;

    /** One provider request; a hung request is cut off well before the queue would redeliver the job. */
    public int $timeout = 60;

    /**
     * Keep the uniqueness lock for longer than all retries can take.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(EmailService $emails): void
    {
        $message = Message::find($this->messageId);

        // The organization (and with it the message) may have been deleted since queuing.
        if ($message === null) {
            return;
        }

        $emails->deliver($message);
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::find($this->messageId);

        if ($message !== null) {
            app(EmailService::class)->markFailedIfUnsent(
                $message,
                'The email provider did not respond after several attempts.',
            );
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AI\CustomerReplyClassificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Classifies one customer reply. Analysis only: never contacts anyone or changes business data.
 *
 * Transient provider failures (timeouts, 429, 5xx) are retried with backoff; invalid AI
 * output and configuration errors are recorded and not retried.
 */
class ClassifyCustomerReplyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $messageId,
        public readonly string $requestId,
        public readonly bool $reclassify = false,
    ) {}

    public static function automatic(Message $message): self
    {
        return new self($message->id, CustomerReplyClassificationService::automaticRequestId($message));
    }

    public static function reclassification(Message $message): self
    {
        return new self($message->id, 'reclassify-'.Str::uuid(), reclassify: true);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function handle(CustomerReplyClassificationService $classifier): void
    {
        $message = Message::find($this->messageId);

        if ($message !== null) {
            $classifier->classify($message, $this->requestId, $this->reclassify);
        }
    }
}

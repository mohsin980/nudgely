<?php

namespace App\Jobs;

use App\Contracts\WhatsApp\WhatsAppApiInterface;
use App\Enums\MessageStatus;
use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Hands one queued WhatsApp message to Meta. A transient failure goes back to queued and is retried;
 * a rejected message is marked failed with a reason the business can read.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WhatsAppApiInterface $api): void
    {
        $message = Message::query()->whereKey($this->messageId)->first();

        // Only a queued message is sent: a retry or duplicate job never sends the same message twice.
        if ($message === null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $connection = WhatsAppConnection::query()->where('organization_id', $message->organization_id)->first();

        if ($connection === null || ! $connection->isActive()) {
            $this->fail($message, 'WhatsApp is not connected.');

            return;
        }

        $this->move($message, MessageStatus::Sending);

        $to = ltrim((string) $message->to_address, '+');

        try {
            $providerMessageId = $message->metadata['kind'] === 'template'
                ? $api->sendTemplate($connection, $to, $message->metadata['template'], $message->metadata['language'], $message->metadata['parameters'])
                : $api->sendText($connection, $to, (string) $message->body_text);
        } catch (WhatsAppApiException $e) {
            if ($e->isTransient()) {
                $this->move($message, MessageStatus::Queued);

                throw $e;
            }

            Log::warning('whatsapp.send_rejected', ['message_id' => $message->id, 'organization_id' => $message->organization_id, 'reason' => $e->getMessage()]);
            $this->fail($message, 'WhatsApp did not accept this message. Check the number and try again.');

            return;
        }

        $message->forceFill([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::query()->whereKey($this->messageId)->first();

        if ($message !== null && in_array($message->status, [MessageStatus::Queued, MessageStatus::Sending], true)) {
            $this->fail($message, 'WhatsApp could not be reached. Try sending again.');
        }
    }

    private function move(Message $message, MessageStatus $next): void
    {
        if (! $message->status->canTransitionTo($next)) {
            throw new LogicException('Invalid WhatsApp message status change.');
        }

        $message->forceFill(['status' => $next])->save();
    }

    private function fail(Message $message, string $reason): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }
}

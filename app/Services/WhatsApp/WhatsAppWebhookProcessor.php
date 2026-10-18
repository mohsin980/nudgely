<?php

namespace App\Services\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a stored WhatsApp webhook into messages and delivery updates.
 *
 * The phone_number_id in the payload selects the organization, through the connection that owns it.
 * Customers are matched by phone digits inside that organization only. A message from an unknown number
 * is kept for review rather than creating a customer. The provider's message ID is unique per direction,
 * so a repeated delivery never stores a message twice.
 */
class WhatsAppWebhookProcessor
{
    public const OUTCOME_PROCESSED = 'processed';

    public const OUTCOME_UNROUTABLE = 'unroutable';

    public const OUTCOME_ALREADY_PROCESSED = 'already_processed';

    private const MAX_TEXT = 10000;

    public function process(WebhookEvent $event): string
    {
        if ($event->isFinished()) {
            return self::OUTCOME_ALREADY_PROCESSED;
        }

        $event->markProcessing();

        try {
            $routed = DB::transaction(fn () => $this->handle($event));
        } catch (Throwable $e) {
            $event->refresh()->markAttemptFailed('Processing attempt failed; it will be retried.');
            Log::error('webhook.failed', ['event' => 'webhook.failed', 'webhook_event_id' => $event->id, 'provider' => 'whatsapp', 'exception' => $e::class]);

            throw $e;
        }

        if (! $routed) {
            $event->markIgnored('No active WhatsApp connection matches this event.');
            Log::warning('webhook.ignored', ['event' => 'webhook.ignored', 'webhook_event_id' => $event->id, 'provider' => 'whatsapp', 'reason' => 'unroutable']);

            return self::OUTCOME_UNROUTABLE;
        }

        $event->markProcessed();

        return self::OUTCOME_PROCESSED;
    }

    /**
     * @return bool Whether at least one change belonged to an active connection.
     */
    private function handle(WebhookEvent $event): bool
    {
        $routed = false;

        foreach ($event->payload['entry'] ?? [] as $entry) {
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                $value = $change['value'] ?? null;

                if (($change['field'] ?? null) !== 'messages' || ! is_array($value)) {
                    continue;
                }

                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $connection = $phoneNumberId === '' ? null : WhatsAppConnection::query()->where('phone_number_id', $phoneNumberId)->first();

                if ($connection === null || ! $connection->isActive()) {
                    continue;
                }

                $routed = true;
                $event->forceFill(['organization_id' => $connection->organization_id])->save();

                foreach ($this->list($value['messages'] ?? null) as $message) {
                    $this->storeInbound($connection, $message, $this->list($value['contacts'] ?? null), $event);
                }

                foreach ($this->list($value['statuses'] ?? null) as $status) {
                    $this->applyStatus($connection, $status);
                }
            }
        }

        return $routed;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $contacts
     */
    private function storeInbound(WhatsAppConnection $connection, array $message, array $contacts, WebhookEvent $event): void
    {
        $providerMessageId = is_string($message['id'] ?? null) ? $message['id'] : '';
        $phone = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?: '';

        if ($providerMessageId === '' || $phone === '') {
            return;
        }

        $type = is_string($message['type'] ?? null) ? $message['type'] : 'unknown';
        $text = $type === 'text' && is_string($message['text']['body'] ?? null) ? $message['text']['body'] : '['.$type.' message]';
        $contactName = collect($contacts)->firstWhere('wa_id', $phone)['profile']['name'] ?? null;
        $receivedAt = $this->receivedAt($message['timestamp'] ?? null, $event);

        try {
            // A savepoint: a repeated message rolls back only itself, never the whole event.
            DB::transaction(function () use ($connection, $providerMessageId, $phone, $type, $text, $contactName, $receivedAt) {
                $customer = Customer::query()
                    ->where('organization_id', $connection->organization_id)
                    ->where('phone_digits', $phone)
                    ->first();

                $conversation = $customer === null ? null : $this->conversationFor($customer, $receivedAt);

                $stored = new Message;
                $stored->forceFill([
                    'organization_id' => $connection->organization_id,
                    'conversation_id' => $conversation?->id,
                    'direction' => MessageDirection::Inbound,
                    'channel' => MessageChannel::WhatsApp,
                    'provider' => WhatsAppConnection::PROVIDER,
                    'from_address' => '+'.$phone,
                    'to_address' => $connection->display_phone_number,
                    'from_name' => $contactName === null ? null : mb_substr($contactName, 0, 255),
                    'subject' => '',
                    'body_text' => mb_substr($text, 0, self::MAX_TEXT),
                    'provider_message_id' => $providerMessageId,
                    'status' => $conversation === null ? MessageStatus::NeedsReview : MessageStatus::Received,
                    'received_at' => $receivedAt,
                    'metadata' => array_filter([
                        'whatsapp_type' => $type,
                        'review_reason' => $conversation === null ? 'unknown_sender' : null,
                    ]),
                ])->save();

                $conversation?->recordActivity($receivedAt);
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('whatsapp.duplicate_message', ['organization_id' => $connection->organization_id, 'webhook_event_id' => $event->id]);
        }
    }

    /**
     * The customer's most recent conversation, or a new one, so a reply lands in the thread it belongs to.
     */
    private function conversationFor(Customer $customer, CarbonImmutable $receivedAt): Conversation
    {
        $conversation = Conversation::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->latest('last_message_at')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = new Conversation;
        $conversation->forceFill([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => $receivedAt,
        ])->save();

        return $conversation;
    }

    /**
     * Delivery updates only move a message forward, and only when the state machine allows it.
     *
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(WhatsAppConnection $connection, array $status): void
    {
        $providerMessageId = is_string($status['id'] ?? null) ? $status['id'] : '';

        $message = $providerMessageId === '' ? null : Message::query()
            ->where('organization_id', $connection->organization_id)
            ->where('channel', MessageChannel::WhatsApp)
            ->where('direction', MessageDirection::Outbound)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($message === null) {
            return;
        }

        $next = match ($status['status'] ?? null) {
            'sent' => MessageStatus::Sent,
            'delivered', 'read' => MessageStatus::Delivered,
            'failed' => $message->status->canTransitionTo(MessageStatus::Failed) ? MessageStatus::Failed : MessageStatus::Bounced,
            default => null,
        };

        if ($next === null || $next === $message->status || ! $message->status->canTransitionTo($next, providerEvidence: true)) {
            return;
        }

        $message->forceFill(['status' => $next] + match ($next) {
            MessageStatus::Delivered => ['delivered_at' => now()],
            MessageStatus::Failed, MessageStatus::Bounced => [
                'failed_at' => now(),
                'failure_reason' => mb_substr((string) ($status['errors'][0]['title'] ?? 'Meta could not deliver this message.'), 0, 255),
            ],
            default => [],
        })->save();
    }

    private function receivedAt(mixed $timestamp, WebhookEvent $event): CarbonImmutable
    {
        // Meta signs its webhooks, so the sender's timestamp can be used to order the thread.
        return is_string($timestamp) && ctype_digit($timestamp)
            ? CarbonImmutable::createFromTimestamp((int) $timestamp)
            : CarbonImmutable::instance($event->received_at ?? now());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $items): array
    {
        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }
}

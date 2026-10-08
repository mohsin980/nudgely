<?php

namespace App\Services\Email;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\Email\InvalidDeliveryEventException;
use App\Exceptions\Email\MessageNotYetSentException;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Email\Data\DeliveryEvent;
use App\Services\Email\Events\PostmarkDeliveryEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a provider delivery event to the outbound message it describes.
 *
 * The message is found by provider and provider message ID, and the organization comes from that
 * message, never from the payload. Every change goes through MessageStatus::canTransitionTo, so a
 * repeated or late event is recorded as ignored instead of moving a message backwards.
 */
class DeliveryEventProcessor
{
    public const APPLIED = 'applied';

    public const RECONCILED = 'reconciled';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    public const UNKNOWN_MESSAGE = 'unknown_message';

    public const INVALID = 'invalid';

    public function __construct(private readonly PostmarkDeliveryEvents $events) {}

    public function process(WebhookEvent $event): string
    {
        if ($event->isFinished()) {
            return self::DUPLICATE;
        }

        $started = hrtime(true);
        $event->markProcessing();

        try {
            $outcome = DB::transaction(fn () => $this->applyLocked($event->id));
        } catch (Throwable $e) {
            $event->refresh()->markAttemptFailed('Processing attempt failed; it will be retried.');
            Log::error('webhook.failed', ['event' => 'webhook.failed', 'webhook_event_id' => $event->id, 'attempt' => $event->attempt_count, 'exception' => $e::class]);

            throw $e;
        }

        Log::info('webhook.processed', [
            'event' => 'webhook.processed',
            'webhook_event_id' => $event->id,
            'provider' => $event->provider,
            'organization_id' => $event->refresh()->organization_id,
            'outcome' => $outcome,
            'attempt' => $event->attempt_count,
            'correlation_id' => $event->correlation_id,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
        ]);

        return $outcome;
    }

    private function applyLocked(int $eventId): string
    {
        $event = WebhookEvent::query()->whereKey($eventId)->lockForUpdate()->first();

        if ($event === null || $event->isFinished()) {
            return self::DUPLICATE;
        }

        try {
            $delivery = $this->events->parse($event->payload);
        } catch (InvalidDeliveryEventException) {
            $event->markFailed('Payload is not a valid delivery event.');

            return self::INVALID;
        }

        $message = Message::query()
            ->where('provider', $event->provider)
            ->where('direction', MessageDirection::Outbound)
            ->where('provider_message_id', $delivery->providerMessageId)
            ->lockForUpdate()
            ->first();

        if ($message === null) {
            $event->markIgnored('No outbound message matches this event.');

            return self::UNKNOWN_MESSAGE;
        }

        // The message's organization is the trusted one; the payload only ever names the message.
        $event->forceFill(['organization_id' => $message->organization_id])->save();

        if ($delivery->type === DeliveryEvent::TEMPORARY) {
            $event->markIgnored('Temporary bounce: the provider will keep trying.');

            return self::IGNORED;
        }

        if (strtolower((string) $message->to_address) !== $delivery->recipient) {
            $event->markIgnored('The recipient does not match this message.');
            Log::warning('webhook.ignored', ['event' => 'webhook.ignored', 'webhook_event_id' => $event->id, 'message_id' => $message->id, 'reason' => 'recipient_mismatch']);

            return self::INVALID;
        }

        // A delivery report can overtake the send job that records "sent". Retry instead of dropping it.
        if (in_array($message->status, [MessageStatus::Queued, MessageStatus::Sending], true)) {
            throw new MessageNotYetSentException('The message is not recorded as sent yet.');
        }

        $target = match ($delivery->type) {
            DeliveryEvent::DELIVERED => MessageStatus::Delivered,
            DeliveryEvent::BOUNCED => MessageStatus::Bounced,
            DeliveryEvent::COMPLAINED => MessageStatus::Complained,
        };

        if ($message->status === $target) {
            $event->markIgnored('Already recorded.');

            return self::DUPLICATE;
        }

        $reconciling = $message->status === MessageStatus::Failed;

        if (! $message->status->canTransitionTo($target, providerEvidence: true)) {
            $event->markIgnored("A message that is {$message->status->value} cannot become {$target->value}.");

            return self::IGNORED;
        }

        $message->forceFill(array_merge(
            ['status' => $target, 'updated_at' => now()],
            match ($target) {
                MessageStatus::Delivered => ['delivered_at' => now()],
                MessageStatus::Bounced => ['bounced_at' => now()],
                MessageStatus::Complained => ['complained_at' => now()],
            },
            // A reconciled message no longer reads as failed.
            $reconciling ? ['failed_at' => null, 'failure_reason' => null] : [],
        ))->save();

        if (in_array($target, [MessageStatus::Bounced, MessageStatus::Complained], true)) {
            $this->suppressAddress($message, $target);
        }

        $event->markProcessed();

        $context = [
            'event' => 'email.'.$target->value,
            'organization_id' => $message->organization_id,
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'provider' => $message->provider->value,
            'provider_message_id' => $message->provider_message_id,
            'webhook_event_id' => $event->id,
            'status' => $target->value,
            'correlation_id' => $message->correlation_id,
        ];

        $reconciling
            ? Log::warning('email.reconciled', $context + ['event' => 'email.reconciled', 'previous_status' => 'failed'])
            : Log::info($context['event'], $context);

        return $reconciling ? self::RECONCILED : self::APPLIED;
    }

    /**
     * A bounced or complained address is not emailed again until the customer's address changes.
     * The customer record itself is kept.
     */
    private function suppressAddress(Message $message, MessageStatus $reason): void
    {
        if ($message->conversation_id === null) {
            return;
        }

        $customer = Customer::query()
            ->where('organization_id', $message->organization_id)
            ->whereIn('id', DB::table('conversations')->where('id', $message->conversation_id)->select('customer_id'))
            ->first();

        if ($customer === null || strtolower((string) $customer->email) !== strtolower((string) $message->to_address)) {
            return;
        }

        $customer->forceFill([
            'email_suppressed_address' => strtolower((string) $customer->email),
            'email_suppression_reason' => $reason->value,
            'email_suppressed_at' => now(),
        ])->save();
    }
}

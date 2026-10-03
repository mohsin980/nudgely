<?php

namespace App\Services\Email;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\CustomerReplyReceived;
use App\Exceptions\Email\InvalidInboundEmailException;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailReplyRoute;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\AI\CustomerReplyClassificationService;
use App\Services\Email\Data\InboundEmail;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a stored inbound-email webhook event into an inbound Message.
 *
 * The secure reply route is the only source of organization and conversation. Nothing in
 * the payload (headers, addresses, IDs) can select another tenant's data. Processing runs in
 * a transaction with the event row locked, so a retry or concurrent worker never stores the
 * email twice; a unique index on inbound provider message IDs is the final guarantee.
 */
class InboundEmailProcessor
{
    public const OUTCOME_ATTACHED = 'attached';

    public const OUTCOME_NEEDS_REVIEW = 'needs_review';

    public const OUTCOME_UNROUTABLE = 'unroutable';

    public const OUTCOME_INVALID = 'invalid';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_ALREADY_PROCESSED = 'already_processed';

    public function __construct(
        private readonly InboundEmailProviderManager $providers,
        private readonly ReplyRouteService $replyRoutes,
        private readonly EmailHtmlSanitizer $sanitizer,
    ) {}

    public function process(WebhookEvent $event): string
    {
        try {
            $outcome = DB::transaction(fn () => $this->processLocked($event->id));
        } catch (UniqueConstraintViolationException) {
            // Another event already stored this provider message.
            $this->finish($event->id, failureReason: null);
            $outcome = self::OUTCOME_DUPLICATE;
        }

        Log::info('Inbound email processed.', ['webhook_event_id' => $event->id, 'outcome' => $outcome]);

        return $outcome;
    }

    private function processLocked(int $eventId): string
    {
        $event = WebhookEvent::query()->whereKey($eventId)->lockForUpdate()->first();

        if ($event === null || $event->isFinished()) {
            return self::OUTCOME_ALREADY_PROCESSED;
        }

        try {
            $email = $this->providers->driver($event->provider)->parse($event->payload);
        } catch (InvalidInboundEmailException) {
            $this->finish($event->id, failureReason: 'Payload is not a valid inbound email.');

            return self::OUTCOME_INVALID;
        }

        $token = $this->replyRoutes->extractToken($email->recipients);
        $route = $token === null ? null : $this->replyRoutes->resolve($token);
        [$conversation, $customer] = $route === null ? [null, null] : $this->routeTarget($route);

        if ($route === null || $conversation === null || $customer === null) {
            $this->finish($event->id, failureReason: 'No active reply route matches this email.');
            Log::warning('Inbound email has no usable reply route.', ['webhook_event_id' => $event->id]);

            return self::OUTCOME_UNROUTABLE;
        }

        $senderMatches = hash_equals($customer->email, $email->fromEmail);

        // Our receipt time, not the sender-controlled Date header, so a forged date cannot reorder threads.
        $message = $this->storeMessage($email, $route, $senderMatches ? $conversation : null, $token, $event->created_at);

        if ($senderMatches) {
            $conversation->recordActivity($message->received_at);

            // Announce only; dispatched after the transaction commits.
            event(new CustomerReplyReceived($route->organization_id, $message->id, $conversation->id, $customer->id));

            // Analysis only, after commit and off the webhook path. Held-for-review replies are not classified.
            if (config('ai.classification.enabled')) {
                ClassifyCustomerReplyJob::dispatch($message->id, CustomerReplyClassificationService::automaticRequestId($message))->afterCommit();
            }
        }

        $this->finish($event->id, failureReason: null);

        Log::info('Inbound email stored.', [
            'webhook_event_id' => $event->id,
            'provider_message_id' => $email->providerMessageId,
            'organization_id' => $route->organization_id,
            'conversation_id' => $senderMatches ? $conversation->id : null,
            'message_id' => $message->id,
            'status' => $message->status->value,
        ]);

        return $senderMatches ? self::OUTCOME_ATTACHED : self::OUTCOME_NEEDS_REVIEW;
    }

    /**
     * The route's conversation and customer, both looked up inside the route's organization.
     *
     * @return array{0: ?Conversation, 1: ?Customer}
     */
    private function routeTarget(EmailReplyRoute $route): array
    {
        $conversation = Conversation::query()
            ->where('organization_id', $route->organization_id)
            ->find($route->conversation_id);

        $customer = $conversation === null ? null : Customer::query()
            ->where('organization_id', $route->organization_id)
            ->find($conversation->customer_id);

        return [$conversation, $customer];
    }

    private function storeMessage(InboundEmail $email, EmailReplyRoute $route, ?Conversation $conversation, string $token, \DateTimeInterface $receivedAt): Message
    {
        $maxBody = config('email.inbound.max_body_kb') * 1024;

        $message = new Message;
        $message->forceFill([
            'organization_id' => $route->organization_id,
            'conversation_id' => $conversation?->id,
            'email_reply_route_id' => $route->id,
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'provider' => $email->provider,
            'from_address' => mb_substr($email->fromEmail, 0, 254),
            'from_name' => $email->fromName === null ? null : mb_substr($email->fromName, 0, 255),
            'to_address' => $this->replyRoutes->replyAddressFor($token),
            'subject' => mb_substr(trim(str_replace(["\r", "\n"], ' ', $email->subject)), 0, 998),
            'body_text' => $email->textBody === null ? null : mb_strcut($email->textBody, 0, $maxBody),
            'body_html' => $this->sanitizer->sanitize($email->htmlBody === null ? null : mb_strcut($email->htmlBody, 0, $maxBody)),
            'provider_message_id' => $email->providerMessageId,
            'header_message_id' => $email->messageId,
            'in_reply_to' => $email->inReplyTo,
            'references' => $email->references === [] ? null : implode(' ', $email->references),
            'status' => $conversation === null ? MessageStatus::NeedsReview : MessageStatus::Received,
            'received_at' => $receivedAt,
            'metadata' => array_filter([
                'email_date' => $email->receivedAt->toIso8601String(),
                'attachments' => $email->attachments ?: null,
                'review_reason' => $conversation === null ? 'sender_mismatch' : null,
            ]) ?: null,
        ])->save();

        return $message;
    }

    private function finish(int $eventId, ?string $failureReason): void
    {
        WebhookEvent::query()->whereKey($eventId)->update($failureReason === null
            ? ['processed_at' => now(), 'updated_at' => now()]
            : ['failed_at' => now(), 'failure_reason' => $failureReason, 'updated_at' => now()]);
    }
}

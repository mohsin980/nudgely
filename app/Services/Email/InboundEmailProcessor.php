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
use App\Services\Email\Bounces\BounceNoticeParser;
use App\Services\Email\Bounces\BounceRecorder;
use App\Services\Email\Bounces\BounceTokens;
use App\Services\Email\Data\InboundEmail;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public const OUTCOME_BOUNCE = 'bounce';

    public const OUTCOME_BOUNCE_IGNORED = 'bounce_ignored';

    public const OUTCOME_ALREADY_PROCESSED = 'already_processed';

    public function __construct(
        private readonly InboundEmailProviderManager $providers,
        private readonly ReplyRouteService $replyRoutes,
        private readonly EmailHtmlSanitizer $sanitizer,
        private readonly BounceNoticeParser $bounceParser,
        private readonly BounceRecorder $bounces,
    ) {}

    public function process(WebhookEvent $event): string
    {
        if ($event->isFinished()) {
            return self::OUTCOME_ALREADY_PROCESSED;
        }

        $started = hrtime(true);
        $event->markProcessing();

        try {
            $outcome = DB::transaction(fn () => $this->processLocked($event->id));
        } catch (UniqueConstraintViolationException) {
            // Another event already stored this provider message.
            $event->refresh()->markIgnored('Duplicate of an email that was already stored.');
            $outcome = self::OUTCOME_DUPLICATE;
        } catch (Throwable $e) {
            // Retried by the job; the reason is kept generic, the exception class goes to the log only.
            $this->assignOrganizationFromRoute($event);
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

    /**
     * A failed attempt can happen before the event was traced to a tenant. The reply token in the stored
     * payload still names the route, so the organization can be recorded for an operator. The route lookup is
     * the same trusted one used for normal processing; nothing else in the payload decides the tenant.
     */
    public function assignOrganizationFromRoute(WebhookEvent $event): void
    {
        if ($event->organization_id !== null || $event->event_type !== WebhookEvent::TYPE_INBOUND_EMAIL) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $recipients = array_merge(
            array_map('trim', explode(',', (string) ($payload['To'] ?? ''))),
            array_column(is_array($payload['ToFull'] ?? null) ? $payload['ToFull'] : [], 'Email'),
            [(string) ($payload['OriginalRecipient'] ?? '')],
        );

        $token = $this->replyRoutes->extractToken(array_values(array_filter($recipients, fn ($r) => is_string($r) && $r !== '')));
        $route = $token === null ? null : $this->replyRoutes->resolve($token);

        if ($route !== null) {
            $event->forceFill(['organization_id' => $route->organization_id])->save();
        }
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
            $event->markFailed('Payload is not a valid inbound email.');

            return self::OUTCOME_INVALID;
        }

        // A bounce notice for an SMTP message: it is addressed to that message's bounce address, not a reply address.
        if (($bounced = $this->bounceMessage($email->recipients)) !== null) {
            return $this->applySmtpBounce($event, $bounced, $email);
        }

        $token = $this->replyRoutes->extractToken($email->recipients);
        $route = $token === null ? null : $this->replyRoutes->resolve($token);
        [$conversation, $customer] = $route === null ? [null, null] : $this->routeTarget($route);

        if ($route === null || $conversation === null || $customer === null) {
            // Nothing to attach to, and no tenant is known, so nothing is stored: recorded as ignored.
            $event->markIgnored('No active reply route matches this email.');
            Log::warning('webhook.ignored', ['event' => 'webhook.ignored', 'webhook_event_id' => $event->id, 'provider' => $event->provider, 'reason' => 'unroutable']);

            return self::OUTCOME_UNROUTABLE;
        }

        // Trusted: taken from the reply route, which the token located, never from the payload.
        $event->forceFill(['organization_id' => $route->organization_id])->save();

        $senderMatches = hash_equals($customer->email, $email->fromEmail);

        // Our receipt time, not the sender-controlled Date header, so a forged date cannot reorder threads.
        $message = $this->storeMessage($email, $route, $senderMatches ? $conversation : null, $token, $event->created_at);

        // An out-of-office or other automatic reply is kept in the thread, but it is not a conversation turn:
        // it does not move the conversation, stop follow-ups, or get classified.
        if ($senderMatches && ! $this->isAutomatedReply($email)) {
            $conversation->recordActivity($message->received_at);

            // Announce only; dispatched after the transaction commits.
            event(new CustomerReplyReceived($route->organization_id, $message->id, $conversation->id, $customer->id));

            // Analysis only, after commit and off the webhook path. Held-for-review replies are not classified.
            if (config('ai.classification.enabled')) {
                ClassifyCustomerReplyJob::dispatch($message->id, CustomerReplyClassificationService::automaticRequestId($message))->afterCommit();
            }
        }

        $event->markProcessed();

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
     * The sent message a bounce address names. The signed token means only a message we sent can be matched.
     *
     * @param  list<string>  $recipients
     */
    private function bounceMessage(array $recipients): ?Message
    {
        $domain = preg_quote(strtolower((string) config('email.inbound.reply_domain')), '/');

        foreach ($recipients as $recipient) {
            if (preg_match('/^bounce\+([\d.a-f]+)@'.$domain.'$/', strtolower(trim($recipient)), $matches)
                && ($messageId = BounceTokens::messageIdFrom($matches[1])) !== null) {
                return Message::query()->whereKey($messageId)->where('direction', MessageDirection::Outbound)->first();
            }
        }

        return null;
    }

    private function applySmtpBounce(WebhookEvent $event, Message $message, InboundEmail $email): string
    {
        // The message's organization is the trusted one, as for reply routes.
        $event->forceFill(['organization_id' => $message->organization_id])->save();

        if (! $this->bounceParser->isPermanent($this->bounceParser->statusCode($email->textBody ?? ''))) {
            $event->markIgnored('Not a permanent bounce.');

            return self::OUTCOME_BOUNCE_IGNORED;
        }

        $this->bounces->recordPermanentBounce($message, 'smtp');
        $event->markProcessed();

        return self::OUTCOME_BOUNCE;
    }

    /**
     * Auto-replies (RFC 3834 Auto-Submitted, bulk or auto-reply Precedence, vacation responder headers).
     */
    private function isAutomatedReply(InboundEmail $email): bool
    {
        $autoSubmitted = strtolower(trim($email->headers['auto-submitted'] ?? ''));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if (in_array(strtolower(trim($email->headers['precedence'] ?? '')), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }

        return isset($email->headers['x-autoreply']) || isset($email->headers['x-autorespond']);
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
                // Stored, so follow-up stop rules can tell a real reply from an automatic one.
                'automated' => $this->isAutomatedReply($email) ? '1' : null,
            ]) ?: null,
        ])->save();

        return $message;
    }
}

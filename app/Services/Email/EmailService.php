<?php

namespace App\Services\Email;

use App\Enums\Billing\LimitKey;
use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\SendEmailJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Billing\EntitlementService;
use App\Services\Email\Data\EmailSendResult;
use App\Services\Email\Data\OutboundEmail;
use App\Support\CorrelationId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single entry point for sending email on behalf of an organization.
 *
 * Email is always sent from the organization's verified business sender, through the
 * configured provider, and recorded as a Message. Callers never talk to providers directly.
 */
class EmailService
{
    /** Message types that don't count against (or wait for) the monthly email limit. */
    private const UNMETERED_TYPES = ['team_invitation', 'team_notification'];

    public const TEST_EMAIL_SUBJECT = 'QuoteFollow test email';

    public const TEST_EMAIL_BODY = 'This is a test email from QuoteFollow. Your business email connection is working correctly.';

    public function __construct(
        private readonly EmailProviderManager $providers,
        private readonly ReplyRouteService $replyRoutes,
    ) {}

    /**
     * Queue an email from the organization's default sender. Use this for automated sends.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws EmailSendingNotAllowedException
     */
    public function send(
        Organization $organization,
        string $to,
        string $subject,
        ?string $html = null,
        ?string $text = null,
        ?string $replyTo = null,
        ?string $toName = null,
        array $metadata = [],
    ): Message {
        $message = $this->createMessage(
            $this->defaultConnection($organization), $to, $subject, $html, $text, $replyTo, $toName, $metadata,
        );

        SendEmailJob::dispatch($message->id)->afterCommit();

        Log::info('Email queued.', ['organization_id' => $message->organization_id, 'message_id' => $message->id]);

        return $message;
    }

    /**
     * Queue an email to a conversation's customer with a fresh secure Reply-To address,
     * so the customer's reply is routed back into this conversation. Customers who opted out are never emailed.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws EmailSendingNotAllowedException
     */
    public function sendToConversation(Conversation $conversation, string $subject, ?string $html = null, ?string $text = null, array $metadata = []): Message
    {
        $organization = $conversation->organization;
        $customer = $organization->customers()->findOrFail($conversation->customer_id);

        if ($customer->hasOptedOutOfEmail()) {
            throw EmailSendingNotAllowedException::optedOut();
        }

        if ($customer->isEmailSuppressed()) {
            throw EmailSendingNotAllowedException::suppressed();
        }

        $connection = $this->defaultConnection($organization);

        $message = DB::transaction(function () use ($conversation, $customer, $connection, $subject, $html, $text, $metadata) {
            $message = $this->createMessage(
                $connection, $customer->email, $subject, $html, $text,
                replyTo: $this->replyRoutes->createFor($conversation),
                toName: $customer->name,
                metadata: $metadata,
                conversationId: $conversation->id,
            );

            // The business has replied: it's now the customer's turn.
            $conversation->recordActivity(now(), ConversationStatus::WaitingCustomer);

            return $message;
        });

        SendEmailJob::dispatch($message->id)->afterCommit();

        $this->logQueued($message);

        return $message;
    }

    /**
     * Send a fixed test email from a specific connection right away, without retries.
     *
     * @throws EmailSendingNotAllowedException
     */
    public function sendTestEmail(EmailConnection $connection, string $to): Message
    {
        $message = $this->createMessage(
            $connection,
            $to,
            self::TEST_EMAIL_SUBJECT,
            '<p>'.e(self::TEST_EMAIL_BODY).'</p>',
            self::TEST_EMAIL_BODY,
            metadata: ['type' => 'test_email'],
        );

        $this->deliver($message, retryTransientFailures: false);

        return $message->refresh();
    }

    /**
     * Hand a queued message to the provider exactly once.
     *
     * Sending eligibility is re-checked because the domain may have changed since the message was queued.
     * Permanent failures are recorded on the message. Transient failures put it back in the queue and
     * rethrow so the job can retry, unless $retryTransientFailures is false.
     *
     * @throws EmailProviderException only for transient failures when retrying is allowed
     */
    public function deliver(Message $message, bool $retryTransientFailures = true): EmailSendResult
    {
        // Atomically claim the message: a duplicate job or a second worker finds nothing to claim.
        $claimed = Message::query()
            ->whereKey($message->getKey())
            ->where('status', MessageStatus::Queued)
            ->update(['status' => MessageStatus::Sending, 'send_attempts' => DB::raw('send_attempts + 1'), 'updated_at' => now()]);

        if ($claimed === 0) {
            $message->refresh();

            return new EmailSendResult($message->status, $message->provider_message_id);
        }

        $message->refresh();

        // Everything before the provider request is safe to retry: nothing has left the server yet.
        try {
            $connection = $message->emailConnection ?? throw EmailSendingNotAllowedException::connectionRemoved();
            $this->assertCanSendFrom($connection, $message->organization_id);

            if ($connection->sender_email !== $message->from_address) {
                throw EmailSendingNotAllowedException::senderMismatch();
            }

            $provider = $this->providers->for($connection->provider);
        } catch (EmailSendingNotAllowedException $e) {
            return $this->markFailed($message, EmailSendResult::failed('not_allowed', $e->getMessage()));
        } catch (Throwable $e) {
            $this->requeue($message);

            throw $e;
        }

        $started = hrtime(true);
        $this->log('email.sending', $message, ['attempt' => $message->send_attempts]);

        try {
            $result = $provider->send(new OutboundEmail(
                organizationId: $message->organization_id,
                fromEmail: $connection->sender_email,
                fromName: $connection->sender_name,
                toEmail: $message->to_address,
                toName: $message->to_name,
                subject: $message->subject,
                textBody: $message->body_text,
                htmlBody: $message->body_html,
                replyTo: $message->reply_to,
                metadata: ['message_id' => (string) $message->id, 'organization_id' => (string) $message->organization_id],
            ));
        } catch (EmailProviderException $e) {
            // A provider that answered with a known temporary error did not accept the email.
            if ($e->isTransient() && $retryTransientFailures) {
                $this->requeue($message);
                $this->log('email.retry_scheduled', $message, ['reason' => $e->reason, 'retryable' => true], 'warning');

                throw $e;
            }

            return $this->markFailed($message, EmailSendResult::failed($e->reason, $e->userMessage()));
        } catch (Throwable $e) {
            // Timeouts and crashes can happen after the provider accepted the email. Resending could
            // duplicate it, so the outcome is recorded as unknown and the message is not retried.
            $this->log('email.failed', $message, ['reason' => EmailProviderException::OUTCOME_UNKNOWN, 'exception' => $e::class], 'warning');

            return $this->markFailed($message, EmailSendResult::failed(EmailProviderException::OUTCOME_UNKNOWN, 'The email provider did not confirm delivery. It was not resent, to avoid sending it twice.'));
        }

        $message->forceFill([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $result->providerMessageId,
            'from_name' => $connection->sender_name,
            'sent_at' => now(),
        ])->save();
        $this->redactIfSensitive($message);

        $this->log('email.sent', $message, [
            'provider_message_id' => $result->providerMessageId,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
        ]);

        return $result;
    }

    /**
     * Put a claimed message back in the queue so a job can retry it.
     */
    private function requeue(Message $message): void
    {
        Message::query()->whereKey($message->getKey())->where('status', MessageStatus::Sending)
            ->update(['status' => MessageStatus::Queued, 'updated_at' => now()]);
        $message->refresh();
    }

    /**
     * A worker that died while a message was "sending" leaves it there forever. Such messages may or
     * may not have reached the customer, so they are marked failed with that reason and never resent.
     *
     * @return int How many were recovered.
     */
    public function recoverStuckSends(int $olderThanMinutes): int
    {
        $cutoff = now()->subMinutes($olderThanMinutes);
        $recovered = 0;

        $ids = Message::query()->where('status', MessageStatus::Sending)->where('updated_at', '<', $cutoff)
            ->orderBy('id')->limit(200)->pluck('id');

        foreach ($ids as $id) {
            $updated = Message::query()->whereKey($id)->where('status', MessageStatus::Sending)->where('updated_at', '<', $cutoff)
                ->update(['status' => MessageStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'Delivery could not be confirmed. It was not resent, to avoid a duplicate.', 'updated_at' => now()]);

            if ($updated === 1) {
                $recovered++;
                $this->redactIfSensitive(Message::query()->findOrFail($id));
                Log::warning('Stuck email recovered without resending.', ['message_id' => $id]);
            }
        }

        return $recovered;
    }

    /**
     * Record a message as failed if it has not already been sent (used when retries are exhausted).
     */
    public function markFailedIfUnsent(Message $message, string $reason): void
    {
        Message::query()
            ->whereKey($message->getKey())
            ->whereIn('status', [MessageStatus::Queued, MessageStatus::Sending])
            ->update(['status' => MessageStatus::Failed, 'failed_at' => now(), 'failure_reason' => $reason, 'updated_at' => now()]);

        $this->log('email.failed', $message, ['reason' => 'retries_exhausted'], 'warning');
    }

    /**
     * Throws unless the connection may send email for the organization.
     *
     * @throws EmailSendingNotAllowedException
     */
    public function assertCanSendFrom(?EmailConnection $connection, int $organizationId): void
    {
        if ($connection === null || $connection->organization_id !== $organizationId) {
            throw EmailSendingNotAllowedException::noSender();
        }

        if (! $connection->isVerified()) {
            throw EmailSendingNotAllowedException::notVerified();
        }

        if (blank($connection->sender_email) || Str::afterLast($connection->sender_email, '@') !== $connection->domain) {
            throw EmailSendingNotAllowedException::senderMismatch();
        }
    }

    private function defaultConnection(Organization $organization): EmailConnection
    {
        $connection = $organization->emailConnections()->default()->first();
        $this->assertCanSendFrom($connection, $organization->id);

        return $connection;
    }

    /**
     * @param  array<string, string>  $metadata
     *
     * @throws EmailSendingNotAllowedException
     */
    private function createMessage(
        EmailConnection $connection,
        string $to,
        string $subject,
        ?string $html,
        ?string $text,
        ?string $replyTo = null,
        ?string $toName = null,
        array $metadata = [],
        ?int $conversationId = null,
    ): Message {
        $this->assertCanSendFrom($connection, $connection->organization_id);

        $to = strtolower(trim($to));
        $replyTo = $replyTo === null ? null : strtolower(trim($replyTo));

        // Sample customers (onboarding demo data) never receive real email, whatever asked for it.
        if (Customer::query()->where('organization_id', $connection->organization_id)->where('is_demo', true)->whereRaw('lower(email) = ?', [$to])->exists()) {
            throw EmailSendingNotAllowedException::sampleCustomer();
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw EmailSendingNotAllowedException::invalidAddress('recipient');
        }

        if ($replyTo !== null && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw EmailSendingNotAllowedException::invalidAddress('reply-to address');
        }

        $save = function () use ($connection, $to, $toName, $replyTo, $subject, $text, $html, $metadata, $conversationId): Message {
            $message = new Message;
            $message->forceFill([
                'organization_id' => $connection->organization_id,
                'conversation_id' => $conversationId,
                'email_connection_id' => $connection->id,
                'direction' => MessageDirection::Outbound,
                'channel' => MessageChannel::Email,
                'provider' => $connection->provider,
                'from_address' => $connection->sender_email,
                'from_name' => $connection->sender_name,
                'to_address' => $to,
                'to_name' => $toName === null ? null : trim(str_replace(["\r", "\n"], ' ', $toName)),
                'reply_to' => $replyTo,
                // Subjects are single-line headers.
                'subject' => trim(str_replace(["\r", "\n"], ' ', $subject)),
                'body_text' => $text,
                'body_html' => $html,
                'metadata' => $metadata ?: null,
                'status' => MessageStatus::Queued,
                // The request's or job's ID, so the message traces back to what caused it.
                'correlation_id' => Context::get(CorrelationId::ATTRIBUTE) ?? CorrelationId::new(),
            ])->save();

            return $message;
        };

        // Customer-facing email counts against the plan (queued and sent ones; failures don't);
        // invitations and team notices never block. The check and the insert share the organization's lock.
        if (in_array($metadata['type'] ?? null, self::UNMETERED_TYPES, true)) {
            return $save();
        }

        try {
            return app(EntitlementService::class)->guard($connection->organization, LimitKey::OutboundEmails, $save);
        } catch (PlanLimitException $e) {
            throw EmailSendingNotAllowedException::planLimit($e->getMessage());
        }
    }

    private function logQueued(Message $message): void
    {
        $this->log('email.queued', $message);
    }

    /**
     * One log shape for every email event, so an operator can follow a message across the request,
     * the job and the provider response. Bodies, addresses and credentials are never included.
     *
     * @param  array<string, mixed>  $extra
     */
    private function log(string $event, Message $message, array $extra = [], string $level = 'info'): void
    {
        Log::log($level, $event, [
            'event' => $event,
            'organization_id' => $message->organization_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'provider' => $message->provider?->value,
            'status' => $message->status?->value,
            'correlation_id' => $message->correlation_id,
        ] + $extra);
    }

    /**
     * Messages whose body is a credential (e.g. an invitation link) keep only a placeholder once
     * the provider has it, so the link isn't left in the database.
     */
    private function redactIfSensitive(Message $message): void
    {
        if (($message->metadata['redact_after_send'] ?? null) === '1') {
            $message->forceFill(['body_text' => '[Removed after sending: this email contained a private link.]', 'body_html' => null])->save();
        }
    }

    private function markFailed(Message $message, EmailSendResult $result): EmailSendResult
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $result->errorMessage,
        ])->save();
        $this->redactIfSensitive($message);

        $this->log('email.failed', $message, ['reason' => $result->errorCode], 'warning');

        return $result;
    }
}

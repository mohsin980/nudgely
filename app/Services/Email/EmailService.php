<?php

namespace App\Services\Email;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\SendEmailJob;
use App\Models\Conversation;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Email\Data\EmailSendResult;
use App\Services\Email\Data\OutboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single entry point for sending email on behalf of an organization.
 *
 * Email is always sent from the organization's verified business sender, through the
 * configured provider, and recorded as a Message. Callers never talk to providers directly.
 */
class EmailService
{
    public const TEST_EMAIL_SUBJECT = 'QuoteFlow test email';

    public const TEST_EMAIL_BODY = 'This is a test email from QuoteFlow. Your business email connection is working correctly.';

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
     * so the customer's reply is routed back into this conversation.
     *
     * @throws EmailSendingNotAllowedException
     */
    public function sendToConversation(Conversation $conversation, string $subject, ?string $html = null, ?string $text = null): Message
    {
        $organization = $conversation->organization;
        $customer = $organization->customers()->findOrFail($conversation->customer_id);
        $connection = $this->defaultConnection($organization);

        $message = DB::transaction(function () use ($conversation, $customer, $connection, $subject, $html, $text) {
            $message = $this->createMessage(
                $connection, $customer->email, $subject, $html, $text,
                replyTo: $this->replyRoutes->createFor($conversation),
                toName: $customer->name,
                conversationId: $conversation->id,
            );

            $conversation->recordActivity(now());

            return $message;
        });

        SendEmailJob::dispatch($message->id)->afterCommit();

        Log::info('Email queued.', [
            'organization_id' => $message->organization_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);

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
            ->update(['status' => MessageStatus::Sending, 'updated_at' => now()]);

        if ($claimed === 0) {
            $message->refresh();

            return new EmailSendResult($message->status, $message->provider_message_id);
        }

        $message->refresh();

        try {
            $connection = $message->emailConnection ?? throw EmailSendingNotAllowedException::connectionRemoved();
            $this->assertCanSendFrom($connection, $message->organization_id);

            if ($connection->sender_email !== $message->from_address) {
                throw EmailSendingNotAllowedException::senderMismatch();
            }

            $result = $this->providers->for($connection->provider)->send(new OutboundEmail(
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
        } catch (EmailSendingNotAllowedException $e) {
            return $this->markFailed($message, EmailSendResult::failed('not_allowed', $e->getMessage()));
        } catch (EmailProviderException $e) {
            if ($e->isTransient() && $retryTransientFailures) {
                $message->forceFill(['status' => MessageStatus::Queued])->save();
                Log::warning('Email send deferred after a transient provider failure.', [
                    'organization_id' => $message->organization_id,
                    'message_id' => $message->id,
                    'reason' => $e->reason,
                ]);

                throw $e;
            }

            return $this->markFailed($message, EmailSendResult::failed($e->reason, $e->userMessage()));
        }

        $message->forceFill([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $result->providerMessageId,
            'from_name' => $connection->sender_name,
            'sent_at' => now(),
        ])->save();

        Log::info('Email sent.', [
            'organization_id' => $message->organization_id,
            'message_id' => $message->id,
            'provider' => $connection->provider->value,
            'provider_message_id' => $result->providerMessageId,
        ]);

        return $result;
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

        Log::warning('Email failed.', ['organization_id' => $message->organization_id, 'message_id' => $message->id, 'reason' => 'retries_exhausted']);
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

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw EmailSendingNotAllowedException::invalidAddress('recipient');
        }

        if ($replyTo !== null && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw EmailSendingNotAllowedException::invalidAddress('reply-to address');
        }

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
        ])->save();

        return $message;
    }

    private function markFailed(Message $message, EmailSendResult $result): EmailSendResult
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $result->errorMessage,
        ])->save();

        Log::warning('Email failed.', [
            'organization_id' => $message->organization_id,
            'message_id' => $message->id,
            'reason' => $result->errorCode,
        ]);

        return $result;
    }
}

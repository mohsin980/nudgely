<?php

namespace App\Services\Conversations;

use App\Enums\ClassificationStatus;
use App\Enums\ConversationCloseReason;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpType;
use App\Enums\MessageDirection;
use App\Exceptions\Conversations\ConversationActionException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\User;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What a person can do with a conversation: reply by email, change status, close/reopen,
 * mark replies read and correct the AI's classification.
 *
 * Every method checks that the acting user belongs to the conversation's organization, so
 * authorization doesn't depend on the UI. Email always goes through EmailService (verified
 * business sender, queued delivery); nothing here talks to a provider or runs automations.
 */
class ConversationService
{
    public const MAX_SUBJECT = 200;

    public const MAX_BODY = 10000;

    public function __construct(
        private readonly EmailService $email,
        private readonly FollowUpService $followUps,
    ) {}

    /**
     * Send an email in an existing conversation. The conversation then waits on the customer.
     *
     * @throws ConversationActionException
     */
    public function reply(User $actor, Conversation $conversation, string $subject, string $body): Message
    {
        $this->assertSameOrganization($actor, $conversation->organization_id);
        [$subject, $body] = $this->validEmail($subject, $body);

        return $this->send($actor, $conversation, $subject, $body);
    }

    /**
     * Email a customer who has no open conversation: starts a new one.
     *
     * @throws ConversationActionException
     */
    public function startWithEmail(User $actor, Customer $customer, string $subject, string $body): Conversation
    {
        $this->assertSameOrganization($actor, $customer->organization_id);
        [$subject, $body] = $this->validEmail($subject, $body);

        return DB::transaction(function () use ($actor, $customer, $subject, $body) {
            $conversation = new Conversation;
            $conversation->forceFill([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'subject' => $subject,
                'status' => ConversationStatus::Open,
                'last_message_at' => now(),
            ])->save();

            $this->send($actor, $conversation, $subject, $body);

            return $conversation;
        });
    }

    /**
     * Set open / waiting on customer / waiting on business. Closing has its own method.
     *
     * @throws ConversationActionException
     */
    public function setStatus(User $actor, Conversation $conversation, ConversationStatus $status): void
    {
        $this->assertSameOrganization($actor, $conversation->organization_id);

        if ($status === ConversationStatus::Closed) {
            $this->close($actor, $conversation);

            return;
        }

        if ($conversation->status === ConversationStatus::Closed) {
            $this->reopen($actor, $conversation, $status);

            return;
        }

        if ($conversation->status === $status) {
            return;
        }

        $from = $conversation->status;
        $conversation->forceFill(['status' => $status])->save();
        ConversationEvent::record($conversation, 'status_changed', $actor, ['from' => $from->value, 'to' => $status->value]);
    }

    /**
     * Close the conversation. Its open automated follow-ups are skipped; nothing is deleted.
     *
     * @throws ConversationActionException
     */
    public function close(User $actor, Conversation $conversation, ?ConversationCloseReason $reason = null, ?string $note = null): void
    {
        $this->assertSameOrganization($actor, $conversation->organization_id);
        $note = $note === null ? null : Str::limit(trim($note), 255, '');

        if ($conversation->status === ConversationStatus::Closed) {
            return;
        }

        DB::transaction(function () use ($actor, $conversation, $reason, $note) {
            $conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now(), 'closed_reason' => $reason])->save();
            ConversationEvent::record($conversation, 'closed', $actor, array_filter(['reason' => $reason?->value, 'note' => $note ?: null]));

            FollowUp::query()
                ->where('organization_id', $conversation->organization_id)
                ->where('conversation_id', $conversation->id)
                ->where('type', FollowUpType::Automated)
                ->open()
                ->pluck('id')
                ->each(fn (int $id) => $this->followUps->skip($id, FollowUpSkipReason::ConversationClosed));
        });

        Log::info('Conversation closed.', ['organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'user_id' => $actor->id]);
    }

    /**
     * @throws ConversationActionException
     */
    public function reopen(User $actor, Conversation $conversation, ConversationStatus $status = ConversationStatus::Open): void
    {
        $this->assertSameOrganization($actor, $conversation->organization_id);

        if ($conversation->status !== ConversationStatus::Closed) {
            return;
        }

        $conversation->forceFill(['status' => $status === ConversationStatus::Closed ? ConversationStatus::Open : $status, 'closed_at' => null, 'closed_reason' => null])->save();
        ConversationEvent::record($conversation, 'reopened', $actor);
    }

    /**
     * Mark this conversation's unread customer replies as read. Other conversations are untouched.
     */
    public function markRead(User $actor, Conversation $conversation): int
    {
        $this->assertSameOrganization($actor, $conversation->organization_id);

        return Message::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * A person corrects the intent of the latest classified reply. The AI's classification is kept;
     * the correction is a new "manual" classification recording who changed what, when and why.
     * Automations are not re-run.
     *
     * @throws ConversationActionException
     */
    public function overrideClassification(User $actor, Conversation $conversation, CustomerReplyIntent $intent, ?string $reason = null): MessageClassification
    {
        $this->assertSameOrganization($actor, $conversation->organization_id);
        $reason = trim((string) $reason);

        if (mb_strlen($reason) > 255) {
            throw ConversationActionException::invalid(['overrideReason' => 'The reason may be up to 255 characters.']);
        }

        return DB::transaction(function () use ($actor, $conversation, $intent, $reason) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $current = $locked->latestClassification()->first()
                ?? throw ConversationActionException::invalid(['overrideIntent' => 'There is no classified reply to correct yet.']);

            if ($current->intent === $intent) {
                throw ConversationActionException::invalid(['overrideIntent' => 'Choose a different intent.']);
            }

            $override = new MessageClassification;
            $override->forceFill([
                'organization_id' => $locked->organization_id,
                'conversation_id' => $locked->id,
                'message_id' => $current->message_id,
                'request_id' => 'override-'.Str::uuid(),
                'status' => ClassificationStatus::Succeeded,
                'source' => 'manual',
                'intent' => $intent,
                'confidence' => null,
                'summary' => $current->summary,
                'requires_human_review' => false,
                'model' => null,
                'overridden_by' => $actor->id,
                'previous_intent' => $current->intent,
                'override_reason' => $reason ?: null,
                'classified_at' => now(),
            ])->save();

            $locked->forceFill(['latest_intent' => $intent, 'latest_confidence' => null, 'needs_attention' => false])->save();
            ConversationEvent::record($locked, 'classification_changed', $actor, array_filter([
                'from' => $current->intent?->value, 'to' => $intent->value, 'reason' => $reason ?: null,
            ]));

            Log::info('Classification corrected.', ['organization_id' => $locked->organization_id, 'conversation_id' => $locked->id, 'user_id' => $actor->id]);
            $conversation->setRawAttributes($locked->getAttributes(), sync: true);

            return $override;
        });
    }

    private function send(User $actor, Conversation $conversation, string $subject, string $body): Message
    {
        $customer = Customer::query()->where('organization_id', $conversation->organization_id)->find($conversation->customer_id)
            ?? throw new ConversationActionException('Customer not found.');

        if ($customer->hasOptedOutOfEmail()) {
            throw new ConversationActionException('Unable to send email: this customer has opted out of email.');
        }

        try {
            return $this->email->sendToConversation(
                $conversation,
                $subject,
                '<p>'.nl2br(e($body)).'</p>',
                $body,
                ['type' => 'manual', 'sent_by' => (string) $actor->id],
            );
        } catch (EmailSendingNotAllowedException $e) {
            Log::warning('Email not sent from conversation.', ['organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'user_id' => $actor->id]);

            // EmailSendingNotAllowedException messages are written for users (e.g. "verify your domain").
            throw new ConversationActionException('Unable to send email. '.$e->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws ConversationActionException
     */
    private function validEmail(string $subject, string $body): array
    {
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $body = trim(str_replace("\r\n", "\n", $body));
        $errors = [];

        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT) {
            $errors['subject'] = 'Enter a subject of up to '.self::MAX_SUBJECT.' characters.';
        }

        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            $errors['body'] = 'Enter a message of up to '.self::MAX_BODY.' characters.';
        }

        if ($errors !== []) {
            throw ConversationActionException::invalid($errors);
        }

        return [$subject, $body];
    }

    private function assertSameOrganization(User $actor, int $organizationId): void
    {
        if ($actor->organization_id === null || $actor->organization_id !== $organizationId) {
            throw new ConversationActionException('Conversation not found.');
        }
    }
}

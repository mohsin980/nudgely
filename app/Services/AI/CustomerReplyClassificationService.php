<?php

namespace App\Services\AI;

use App\Enums\ClassificationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\CustomerReplyClassified;
use App\Exceptions\AI\ClassificationFailedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Services\AI\Data\CustomerReplyClassification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Classifies inbound customer replies and records every attempt.
 *
 * Classification is analysis only: the only writes are a classification row and the
 * conversation's latest_intent / needs_attention flags. Nothing is sent, booked or changed.
 * A successful classification is announced with CustomerReplyClassified (after commit).
 */
class CustomerReplyClassificationService
{
    public function __construct(
        private readonly ReplyClassifierManager $classifiers,
        private readonly ConversationContextBuilder $contexts,
        private readonly ReplyReviewPolicy $policy,
    ) {}

    /**
     * The request ID for a message's automatic classification (one per message).
     */
    public static function automaticRequestId(Message $message): string
    {
        return 'auto-'.$message->id;
    }

    /**
     * Classify a reply. Automatic requests are skipped once any classification succeeded;
     * explicit reclassify requests always add a new row. Both are idempotent per request ID.
     *
     * @throws ClassificationFailedException only for transient failures, so the job can retry
     */
    public function classify(Message $message, string $requestId, bool $reclassify = false): ?MessageClassification
    {
        if (! $this->isClassifiable($message)) {
            return null;
        }

        if ($existing = $this->existingSuccess($message, $requestId, $reclassify)) {
            return $existing;
        }

        try {
            $result = $this->classifiers->driver()->classify($this->contexts->build($message));
        } catch (ClassificationFailedException $e) {
            $this->recordFailure($message, $requestId, $e);

            if ($e->transient) {
                throw $e;
            }

            return null;
        }

        try {
            return DB::transaction(function () use ($message, $requestId, $reclassify, $result) {
                // Serialize concurrent jobs for this message, then re-check before writing.
                Message::query()->whereKey($message->id)->lockForUpdate()->first();

                if ($existing = $this->existingSuccess($message, $requestId, $reclassify)) {
                    return $existing;
                }

                $classification = $this->recordSuccess($message, $requestId, $result);
                $conversation = $this->updateConversation($message, $classification);

                // Announce only; dispatched after this transaction commits, never if it rolls back.
                event(CustomerReplyClassified::fromClassification($classification, $conversation->customer_id));

                return $classification;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingSuccess($message, $requestId, reclassify: true);
        }
    }

    public function isClassifiable(Message $message): bool
    {
        if ($message->direction !== MessageDirection::Inbound || $message->status !== MessageStatus::Received || $message->conversation_id === null) {
            return false;
        }

        // The conversation must belong to the message's organization.
        return Conversation::query()
            ->whereKey($message->conversation_id)
            ->where('organization_id', $message->organization_id)
            ->exists();
    }

    private function existingSuccess(Message $message, string $requestId, bool $reclassify): ?MessageClassification
    {
        return MessageClassification::query()
            ->where('message_id', $message->id)
            ->succeeded()
            ->when($reclassify, fn ($query) => $query->where('request_id', $requestId))
            ->latest('id')
            ->first();
    }

    private function recordSuccess(Message $message, string $requestId, CustomerReplyClassification $result): MessageClassification
    {
        $classification = new MessageClassification;
        $classification->forceFill([
            'organization_id' => $message->organization_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'request_id' => $requestId,
            'status' => ClassificationStatus::Succeeded,
            'intent' => $result->intent,
            'confidence' => $result->confidence,
            'summary' => $result->summary,
            'sentiment' => $result->sentiment,
            'urgency' => $result->urgency,
            'requires_human_review' => $this->policy->requiresHumanReview($result),
            'model' => $result->model,
            'classified_at' => $result->classifiedAt,
        ])->save();

        Log::info('Customer reply classified.', [
            'organization_id' => $message->organization_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'classification_id' => $classification->id,
            'intent' => $result->intent->value,
            'confidence' => $result->confidence,
            'requires_human_review' => $classification->requires_human_review,
        ]);

        return $classification;
    }

    private function recordFailure(Message $message, string $requestId, ClassificationFailedException $e): void
    {
        $failure = new MessageClassification;
        $failure->forceFill([
            'organization_id' => $message->organization_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'request_id' => $requestId,
            'status' => ClassificationStatus::Failed,
            'failure_reason' => mb_substr($e->getMessage(), 0, 255),
        ])->save();

        Log::warning('Customer reply classification failed.', [
            'organization_id' => $message->organization_id,
            'message_id' => $message->id,
            'reason' => $e->getMessage(),
            'transient' => $e->transient,
        ]);
    }

    /**
     * Reflect the classification on the conversation, only if it is for the latest customer reply.
     */
    private function updateConversation(Message $message, MessageClassification $classification): Conversation
    {
        $conversation = Conversation::query()
            ->where('organization_id', $message->organization_id)
            ->lockForUpdate()
            ->findOrFail($message->conversation_id);

        $latestInboundId = $conversation->messages()
            ->where('direction', MessageDirection::Inbound)
            ->orderByRaw('coalesce(received_at, created_at) desc')
            ->orderByDesc('id')
            ->value('id');

        if ($latestInboundId === $message->id) {
            $conversation->forceFill([
                'latest_intent' => $classification->intent,
                'latest_confidence' => $classification->confidence,
                'latest_urgency' => $classification->urgency,
                'needs_attention' => $classification->requires_human_review,
            ])->save();
        }

        return $conversation;
    }
}

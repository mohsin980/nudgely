<?php

namespace App\Services\Dashboard;

use App\Enums\AttentionPriority;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\ReplyUrgency;

/**
 * Decides how urgent a conversation or follow-up is for the business.
 *
 * The AI intent gives a starting point, but business rules have the last word:
 * a low-confidence classification can't make something HIGH, an explicit
 * "waiting on business" status or a review flag lifts an item to at least MEDIUM,
 * and overdue follow-ups are always HIGH.
 */
class AttentionPriorityRules
{
    /**
     * Intents that mean the business should act (also used for "Waiting for You").
     *
     * @var list<CustomerReplyIntent>
     */
    public const ACTION_INTENTS = [
        CustomerReplyIntent::ReadyToBook,
        CustomerReplyIntent::WantsCallback,
        CustomerReplyIntent::Complaint,
        CustomerReplyIntent::Question,
        CustomerReplyIntent::NeedsMoreInformation,
        CustomerReplyIntent::PriceObjection,
    ];

    public function forConversation(
        ConversationStatus $status,
        ?CustomerReplyIntent $intent,
        ?float $confidence,
        ?ReplyUrgency $urgency,
        bool $needsAttention,
    ): AttentionPriority {
        $priority = match ($intent) {
            CustomerReplyIntent::ReadyToBook, CustomerReplyIntent::WantsCallback, CustomerReplyIntent::Complaint => AttentionPriority::High,
            CustomerReplyIntent::Interested, CustomerReplyIntent::Question, CustomerReplyIntent::NeedsMoreInformation, CustomerReplyIntent::PriceObjection => AttentionPriority::Medium,
            default => AttentionPriority::Low,
        };

        if ($urgency === ReplyUrgency::High) {
            $priority = AttentionPriority::High;
        }

        // The AI alone can't make something urgent when it isn't sure.
        if ($priority === AttentionPriority::High && $confidence !== null && $confidence < (float) config('dashboard.high_priority_min_confidence')) {
            $priority = AttentionPriority::Medium;
        }

        // Business rules: someone (or an automation) marked it as waiting on us, or the AI asked for a human.
        if ($status === ConversationStatus::WaitingBusiness || $needsAttention) {
            $priority = AttentionPriority::max($priority, AttentionPriority::Medium);
        }

        return $priority;
    }

    public function forFollowUp(bool $overdue): AttentionPriority
    {
        return $overdue ? AttentionPriority::High : AttentionPriority::Medium;
    }
}

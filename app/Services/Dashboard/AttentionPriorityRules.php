<?php

namespace App\Services\Dashboard;

use App\Enums\AttentionPriority;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\ReplyUrgency;
use App\Models\Conversation;

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

    /**
     * The same rules, from a conversation's stored latest classification.
     */
    public function forConversationModel(Conversation $conversation): AttentionPriority
    {
        return $this->forConversation($conversation->status, $conversation->latest_intent, $conversation->latest_confidence, $conversation->latest_urgency, $conversation->needs_attention);
    }

    /**
     * The same rules as a SQL CASE over the conversations table, for filtering lists in the database.
     * A test checks it agrees with forConversation() for every combination.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function conversationPrioritySql(): array
    {
        $high = ["'ready_to_book'", "'wants_callback'", "'complaint'"];
        $medium = ["'interested'", "'question'", "'needs_more_information'", "'price_objection'"];
        $highIntent = '(latest_intent in ('.implode(', ', $high).") or latest_urgency = 'high')";

        return [
            "(case
                when {$highIntent} and (latest_confidence is null or latest_confidence >= ?) then 'high'
                when {$highIntent} then 'medium'
                when latest_intent in (".implode(', ', $medium).") then 'medium'
                when status = 'waiting_business' or needs_attention = true then 'medium'
                else 'low'
            end)",
            [(float) config('dashboard.high_priority_min_confidence')],
        ];
    }

    public function forFollowUp(bool $overdue): AttentionPriority
    {
        return $overdue ? AttentionPriority::High : AttentionPriority::Medium;
    }
}

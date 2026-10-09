<?php

namespace App\Services\AI;

use App\Enums\ConfidenceLevel;
use App\Enums\CustomerReplyIntent;
use App\Services\AI\Data\CustomerReplyClassification;

/**
 * The application's review policy. The AI may ask for review; it can never waive it.
 *
 * Thresholds and always-reviewed intents live in config/ai.php and nowhere else.
 */
class ReplyReviewPolicy
{
    public function confidenceLevel(float $confidence): ConfidenceLevel
    {
        return match (true) {
            $confidence >= (float) config('ai.classification.thresholds.high', 0.85) => ConfidenceLevel::High,
            $confidence >= (float) config('ai.classification.thresholds.medium', 0.60) => ConfidenceLevel::Medium,
            default => ConfidenceLevel::Low,
        };
    }

    public function requiresHumanReview(CustomerReplyClassification $classification): bool
    {
        return $classification->requiresHumanReview
            || $this->alwaysReviewed($classification->intent)
            || $this->confidenceLevel($classification->confidence) === ConfidenceLevel::Low;
    }

    public function alwaysReviewed(CustomerReplyIntent $intent): bool
    {
        return in_array($intent->value, (array) config('ai.classification.always_review_intents', []), true);
    }
}

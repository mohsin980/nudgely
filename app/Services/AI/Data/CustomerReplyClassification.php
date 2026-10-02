<?php

namespace App\Services\AI\Data;

use App\Enums\CustomerReplyIntent;
use App\Enums\ReplySentiment;
use App\Enums\ReplyUrgency;
use Carbon\CarbonImmutable;

/**
 * A validated classification. Only ever built from output that passed ClassificationOutputValidator.
 */
final readonly class CustomerReplyClassification
{
    public function __construct(
        public CustomerReplyIntent $intent,
        public float $confidence,
        public string $summary,
        public ?ReplySentiment $sentiment,
        public ?ReplyUrgency $urgency,
        // The AI's own suggestion; the application's ReplyReviewPolicy makes the final call.
        public bool $requiresHumanReview,
        public string $model,
        public CarbonImmutable $classifiedAt,
    ) {}
}

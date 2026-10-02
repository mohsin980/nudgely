<?php

namespace Tests\Unit\AI;

use App\Enums\ConfidenceLevel;
use App\Enums\CustomerReplyIntent;
use App\Services\AI\Data\CustomerReplyClassification;
use App\Services\AI\ReplyReviewPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReplyReviewPolicyTest extends TestCase
{
    private function classification(CustomerReplyIntent $intent, float $confidence, bool $aiSaysReview = false): CustomerReplyClassification
    {
        return new CustomerReplyClassification($intent, $confidence, 'summary', null, null, $aiSaysReview, 'm', CarbonImmutable::now());
    }

    public function test_confidence_levels_follow_the_configured_thresholds(): void
    {
        $policy = new ReplyReviewPolicy;

        $this->assertSame(ConfidenceLevel::High, $policy->confidenceLevel(0.85));
        $this->assertSame(ConfidenceLevel::Medium, $policy->confidenceLevel(0.84));
        $this->assertSame(ConfidenceLevel::Medium, $policy->confidenceLevel(0.60));
        $this->assertSame(ConfidenceLevel::Low, $policy->confidenceLevel(0.59));

        config(['ai.classification.thresholds.high' => 0.95]);
        $this->assertSame(ConfidenceLevel::Medium, $policy->confidenceLevel(0.9));
    }

    public function test_low_confidence_always_requires_review(): void
    {
        $this->assertTrue((new ReplyReviewPolicy)->requiresHumanReview($this->classification(CustomerReplyIntent::ReadyToBook, 0.55)));
    }

    public function test_high_confidence_routine_reply_does_not_require_review(): void
    {
        $this->assertFalse((new ReplyReviewPolicy)->requiresHumanReview($this->classification(CustomerReplyIntent::ReadyToBook, 0.97)));
        $this->assertFalse((new ReplyReviewPolicy)->requiresHumanReview($this->classification(CustomerReplyIntent::Question, 0.7)));
    }

    public function test_policy_intents_require_review_even_if_the_ai_disagrees(): void
    {
        foreach ([CustomerReplyIntent::PriceObjection, CustomerReplyIntent::Complaint, CustomerReplyIntent::Unclear, CustomerReplyIntent::WantsCallback] as $intent) {
            $this->assertTrue((new ReplyReviewPolicy)->requiresHumanReview($this->classification($intent, 0.99, aiSaysReview: false)), $intent->value);
        }
    }

    public function test_the_ai_can_ask_for_review(): void
    {
        $this->assertTrue((new ReplyReviewPolicy)->requiresHumanReview($this->classification(CustomerReplyIntent::Interested, 0.95, aiSaysReview: true)));
    }
}

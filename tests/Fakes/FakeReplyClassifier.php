<?php

namespace Tests\Fakes;

use App\Contracts\AI\CustomerReplyClassifierInterface;
use App\Enums\CustomerReplyIntent;
use App\Enums\ReplySentiment;
use App\Enums\ReplyUrgency;
use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\Data\CustomerConversationContext;
use App\Services\AI\Data\CustomerReplyClassification;
use Carbon\CarbonImmutable;

/**
 * Returns queued results (or throws queued exceptions) and records every context it saw.
 */
class FakeReplyClassifier implements CustomerReplyClassifierInterface
{
    /** @var list<CustomerConversationContext> */
    public array $contexts = [];

    /** @var list<CustomerReplyClassification|ClassificationFailedException> */
    public array $queue = [];

    public function willReturn(
        CustomerReplyIntent $intent = CustomerReplyIntent::PriceObjection,
        float $confidence = 0.94,
        string $summary = 'Customer is interested but is negotiating the quoted price.',
        ?ReplySentiment $sentiment = ReplySentiment::Neutral,
        ?ReplyUrgency $urgency = ReplyUrgency::Medium,
        bool $requiresHumanReview = false,
        string $model = 'fake-model-1',
    ): self {
        $this->queue[] = new CustomerReplyClassification($intent, $confidence, $summary, $sentiment, $urgency, $requiresHumanReview, $model, CarbonImmutable::now());

        return $this;
    }

    public function willFail(ClassificationFailedException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function classify(CustomerConversationContext $context): CustomerReplyClassification
    {
        $this->contexts[] = $context;
        $next = array_shift($this->queue) ?? throw new \LogicException('FakeReplyClassifier has nothing queued.');

        if ($next instanceof ClassificationFailedException) {
            throw $next;
        }

        return $next;
    }
}

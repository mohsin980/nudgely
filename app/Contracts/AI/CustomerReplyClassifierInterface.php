<?php

namespace App\Contracts\AI;

use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\Data\CustomerConversationContext;
use App\Services\AI\Data\CustomerReplyClassification;

/**
 * Classifies a customer reply. Implementations only analyze: they never act.
 */
interface CustomerReplyClassifierInterface
{
    /**
     * @throws ClassificationFailedException
     */
    public function classify(CustomerConversationContext $context): CustomerReplyClassification;
}

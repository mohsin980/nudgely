<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A draft estimate was created. Not an automation trigger: a draft is internal until it is sent.
 */
final class EstimateCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $estimateId,
        public readonly int $customerId,
        public readonly ?int $conversationId = null,
    ) {}
}

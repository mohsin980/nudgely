<?php

namespace App\Jobs;

use App\Services\Automation\FollowUpProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one due follow-up. Safe to deliver twice: the follow-up is claimed atomically.
 */
class ProcessFollowUpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $followUpId)
    {
        $this->onQueue(config('automation.queue'));
    }

    public function handle(FollowUpProcessor $processor): void
    {
        $processor->process($this->followUpId);
    }

    public function failed(?Throwable $exception): void
    {
        app(FollowUpProcessor::class)->markFailed($this->followUpId, 'Follow-up failed: it could not be processed.');
    }
}

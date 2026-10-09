<?php

namespace Tests\Fakes;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * A job for the observability tests: records the correlation ID it was dispatched with, then succeeds or fails
 * with a message that contains personal data, to prove the logs scrub it.
 */
class ObservabilityProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> The correlation IDs seen by handle(), in order. */
    public static array $seen = [];

    public function __construct(public string $behaviour = 'ok', public int $tries = 1)
    {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        self::$seen[] = (string) Context::get('correlation_id');

        if ($this->behaviour === 'fail') {
            throw new RuntimeException('Provider rejected jane@acme.com with token abc123def456ghi789jkl012mno345pqr678stu901');
        }
    }
}

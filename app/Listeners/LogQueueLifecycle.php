<?php

namespace App\Listeners;

use App\Support\Logging\ExceptionReporting;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * The lifecycle of every queued job, in one place: dispatched, started, completed, retried, failed, timed out.
 *
 * Levels follow the outcome: routine starts and completions are debug (they are numerous), a retry is a warning,
 * and a job that gave up is an error, the signal alerts are built on. A job's failure is logged here and only
 * here: the exception handler skips it while a job is running, so nothing is written twice.
 */
final class LogQueueLifecycle
{
    /** @var array<string, float> Start times of jobs in progress, keyed by job UUID. */
    private array $startedAt = [];

    public function queued(JobQueued $event): void
    {
        Log::debug('Job queued.', [
            'event' => 'job.queued',
            'job' => $event->payload()['displayName'] ?? 'unknown',
            'connection' => $event->connectionName,
        ]);
    }

    public function processing(JobProcessing $event): void
    {
        $name = $event->job->resolveName();
        $this->startedAt[$event->job->uuid()] = hrtime(true);

        // Shared with the logs written while the job runs (and copied to jobs it dispatches, as a new name overwrites it).
        Context::add('job', $name);

        Log::debug('Job started.', [
            'event' => 'job.started',
            'job' => $name,
            'attempt' => $event->job->attempts(),
            'queue' => $event->job->getQueue(),
        ]);
    }

    public function processed(JobProcessed $event): void
    {
        $name = $event->job->resolveName();

        Log::debug('Job completed.', [
            'event' => 'job.completed',
            'job' => $name,
            'attempt' => $event->job->attempts(),
            'duration_ms' => $this->duration($event->job->uuid()),
        ]);

        Context::forget('job');
    }

    /**
     * A failed attempt. If the job will be tried again, that is a warning; the final failure is reported by failed().
     */
    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $maxTries = $event->job->maxTries();
        $attempt = $event->job->attempts();

        // The exception handler must not log this exception again, whatever happens next.
        ExceptionReporting::markLogged($event->exception);

        if ($maxTries !== null && $attempt >= $maxTries) {
            return;
        }

        Log::warning('Job attempt failed; it will be retried.', [
            'event' => 'job.retry_scheduled',
            'job' => $event->job->resolveName(),
            'attempt' => $attempt,
            'max_attempts' => $maxTries,
            'duration_ms' => $this->duration($event->job->uuid()),
            'retryable' => true,
            'exception' => SensitiveDataRedactor::describe($event->exception),
        ]);
    }

    public function failed(JobFailed $event): void
    {
        ExceptionReporting::markLogged($event->exception);

        Log::error('Job failed permanently.', [
            'event' => 'job.failed',
            'job' => $event->job->resolveName(),
            'attempt' => $event->job->attempts(),
            'duration_ms' => $this->duration($event->job->uuid()),
            'retryable' => false,
            'exception' => SensitiveDataRedactor::describe($event->exception),
        ]);

        Context::forget('job');
    }

    public function timedOut(JobTimedOut $event): void
    {
        Log::error('Job timed out.', [
            'event' => 'job.timed_out',
            'job' => $event->job->resolveName(),
            'attempt' => $event->job->attempts(),
            'duration_ms' => $this->duration($event->job->uuid()),
            'retryable' => true,
        ]);
    }

    private function duration(?string $uuid): ?float
    {
        if ($uuid === null || ! isset($this->startedAt[$uuid])) {
            return null;
        }

        $ms = round((hrtime(true) - $this->startedAt[$uuid]) / 1e6, 1);
        unset($this->startedAt[$uuid]);

        return $ms;
    }
}

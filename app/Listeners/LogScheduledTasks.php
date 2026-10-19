<?php

namespace App\Listeners;

use App\Support\Logging\ExceptionReporting;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;

/**
 * Start, completion and failure of each scheduled task. Completion is debug (scheduled tasks run every minute);
 * a failure, whether an exception or a non-zero exit code, is an error, the signal the scheduler alerts are built on.
 */
final class LogScheduledTasks
{
    public function starting(ScheduledTaskStarting $event): void
    {
        Log::debug('Scheduled task started.', [
            'event' => 'scheduler.task_started',
            'task' => $this->name($event->task),
        ]);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $context = [
            'task' => $this->name($event->task),
            'duration_ms' => round($event->runtime * 1000, 1),
            'exit_code' => $event->task->exitCode,
        ];

        if ($event->task->exitCode !== null && $event->task->exitCode !== 0) {
            Log::error('Scheduled task exited with an error.', ['event' => 'scheduler.task_failed'] + $context);

            return;
        }

        Log::debug('Scheduled task completed.', ['event' => 'scheduler.task_completed'] + $context);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        // The task's failure is logged here, so the exception handler must not log it again.
        ExceptionReporting::markLogged($event->exception);

        Log::error('Scheduled task failed.', [
            'event' => 'scheduler.task_failed',
            'task' => $this->name($event->task),
            'exception' => SensitiveDataRedactor::describe($event->exception),
        ]);
    }

    private function name(Event $task): string
    {
        return (string) ($task->description ?: $task->command ?: 'closure');
    }
}

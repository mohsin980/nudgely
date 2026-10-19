<?php

namespace Tests\Feature\Observability;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SchedulerLoggingTest extends TestCase
{
    private function scheduledTask(): \Illuminate\Console\Scheduling\Event
    {
        return app(Schedule::class)->command('follow-ups:process-due');
    }

    public function test_a_scheduled_task_start_and_completion_are_logged_as_events(): void
    {
        Log::spy();
        $task = $this->scheduledTask();
        $task->exitCode = 0;

        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, 0.25));

        Log::shouldHaveReceived('debug')->with('Scheduled task started.', Mockery::on(fn (array $context) => $context['event'] === 'scheduler.task_started'))->once();
        Log::shouldHaveReceived('debug')->with('Scheduled task completed.', Mockery::on(fn (array $context) => $context['event'] === 'scheduler.task_completed' && $context['duration_ms'] === 250.0 && $context['exit_code'] === 0))->once();
    }

    public function test_a_scheduled_task_that_exits_with_an_error_is_logged_as_a_failure(): void
    {
        Log::spy();
        $task = $this->scheduledTask();
        $task->exitCode = 1;

        event(new ScheduledTaskFinished($task, 0.1));

        Log::shouldHaveReceived('error')->with('Scheduled task exited with an error.', Mockery::on(fn (array $context) => $context['event'] === 'scheduler.task_failed' && $context['exit_code'] === 1))->once();
    }

    public function test_a_scheduled_task_exception_is_logged_once_and_scrubbed(): void
    {
        Log::spy();
        $task = $this->scheduledTask();

        event(new ScheduledTaskFailed($task, new RuntimeException('Connection failed for token sk_live_abcdef123456')));

        Log::shouldHaveReceived('error')->with('Scheduled task failed.', Mockery::on(function (array $context) {
            return $context['event'] === 'scheduler.task_failed'
                && $context['exception']['exception'] === RuntimeException::class
                && ! str_contains((string) json_encode($context), 'sk_live_abcdef123456');
        }))->once();
    }
}

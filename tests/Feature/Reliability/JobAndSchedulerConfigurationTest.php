<?php

use App\Enums\FollowUpStatus;
use App\Jobs\ExecuteAutomationActionJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every queued job class under app/Jobs, by class name.
 *
 * @return list<class-string>
 */
function queuedJobClasses(): array
{
    return collect(File::allFiles(app_path('Jobs')))
        ->map(fn ($file) => 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path('Jobs/'))))
        ->filter(fn (string $class) => is_subclass_of($class, ShouldQueue::class))
        ->values()
        ->all();
}

test('the queue retry window is longer than every job timeout', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($retryAfter)->toBeGreaterThan(0);

    foreach (queuedJobClasses() as $class) {
        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

        // A job still running when the queue redelivers it would run twice.
        expect($timeout)->not->toBeNull("{$class} declares no timeout")
            ->and($timeout)->toBeLessThan($retryAfter, "{$class} can outlive its retry_after");
    }
});

test('a crashed automation step is reclaimed only after its job timeout and before redelivery', function () {
    $jobTimeout = (new ReflectionClass(ExecuteAutomationActionJob::class))->getDefaultProperties()['timeout'];
    $staleAfter = (int) config('automation.retries.stale_after_seconds');
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($staleAfter)->toBeGreaterThan($jobTimeout)->toBeLessThan($retryAfter);
});

test('every queued job retries with a finite number of tries', function () {
    foreach (queuedJobClasses() as $class) {
        $tries = (new ReflectionClass($class))->getDefaultProperties()['tries'] ?? null;

        // ExecuteAutomationActionJob reads its tries from config in its constructor.
        if ($class === ExecuteAutomationActionJob::class) {
            continue;
        }

        expect($tries)->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(5, "{$class} retries too often");
    }
});

test('every scheduled task avoids overlapping and runs on one server only', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue($event->getSummaryForDisplay())
            ->and($event->onOneServer)->toBeTrue($event->getSummaryForDisplay());
    }
});

test('the reliability commands are registered', function () {
    $commands = array_keys(Artisan::all());

    foreach (['email:recover-stuck-sends', 'automations:recover-stalled', 'retention:prune', 'reliability:report'] as $command) {
        expect($commands)->toContain($command);
    }
});

test('a scheduled task does not run while the previous run still holds its lock', function () {
    [$admin, $customer, $conversation] = followUpBusiness();
    $followUp = automatedFollowUp($conversation, ['due_at' => now()->subMinute(), 'status' => FollowUpStatus::Pending]);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'follow-ups:process-due'));
    $lock = Cache::lock($event->mutexName(), 60);

    // Simulate a previous run that is still working.
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('schedule:run')->assertSuccessful();

        expect($followUp->fresh()->status)->toBe(FollowUpStatus::Pending);
    } finally {
        $lock->release();
    }
});

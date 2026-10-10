<?php

namespace App\Services\Automation;

use App\Contracts\Automation\AutomationEvent;
use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationRunStatus;
use App\Exceptions\Automation\AutomationActionRetryException;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Jobs\ExecuteAutomationActionJob;
use App\Jobs\ResumeAutomationRunJob;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Organization;
use App\Services\Team\ActivityNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs automations for events: Event → active automations → conditions → run → actions → results.
 *
 * evaluate() creates one run per matching automation and queues its first action;
 * executeActionRun() runs one action and queues the next, so actions run in order and
 * each in its own job. The run's unique (organization, automation, event type, event ID)
 * index makes repeated or concurrent delivery of the same event a no-op.
 */
class AutomationEngine
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly AutomationActionManager $actions,
        private readonly AutomationExecutionScope $scope,
    ) {}

    /**
     * @return list<AutomationRun> The runs created for this event (none for duplicates).
     */
    public function evaluate(AutomationEvent $event, int $depth = 0): array
    {
        $context = AutomationContext::fromEvent($event, $depth);
        $log = ['organization_id' => $context->organizationId, 'trigger' => $context->triggerType->value, 'event_id' => $context->eventId, 'depth' => $depth];

        $organization = Organization::find($context->organizationId);

        if ($organization === null) {
            Log::warning('Automation event ignored: organization not found.', $log + ['event' => 'automation.event.rejected']);

            return [];
        }

        if (! config('automation.enabled') || ! $organization->automations_enabled) {
            Log::info('Automation event ignored: automations are disabled.', $log + ['event' => 'automation.event.ignored']);

            return [];
        }

        if (! $this->eventBelongsToOrganization($context)) {
            Log::warning('Automation event rejected: its customer or conversation is not in the organization.', $log + ['event' => 'automation.event.rejected']);

            return [];
        }

        // Sample customers (onboarding demo data) never trigger automations.
        if ($context->customer()?->is_demo) {
            Log::info('Automation event ignored: sample customer.', $log + ['event' => 'automation.event.ignored']);

            return [];
        }

        $limit = (int) config('automation.limits.max_automations_per_event');
        $automations = Automation::query()
            ->forOrganization($organization)
            ->activeFor($context->triggerType)
            ->with(['conditions', 'actions'])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        if ($automations->count() > $limit) {
            Log::warning('Automation limit per event reached; extra automations were not evaluated.', $log + ['limit' => $limit, 'event' => 'automation.limit_reached']);
            $automations = $automations->take($limit);
        }

        $runs = [];

        foreach ($automations as $automation) {
            $run = $this->evaluateAutomation($automation, $context);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * Execute one queued action of a run, then queue the next one.
     *
     * @param  bool  $finalAttempt  When false, temporary failures are retried instead of recorded.
     *
     * @throws AutomationActionRetryException when a temporary failure should be retried
     */
    public function executeActionRun(int $actionRunId, bool $finalAttempt = true): ?AutomationActionRun
    {
        $actionRun = $this->claim($actionRunId);

        if ($actionRun === null) {
            return null; // Already handled by another job (duplicate delivery or retry).
        }

        $run = $actionRun->run;
        $context = AutomationContext::fromArray($run->context ?? []);
        $result = $this->guard($run, $actionRun, $context) ?? $this->scope->runAt(
            $context->depth,
            fn () => $this->actions->execute($this->actionFor($run, $actionRun), $context),
        );

        if (! $result->success && ($result->data['retryable'] ?? false) && ! $finalAttempt) {
            $this->release($actionRun);

            throw AutomationActionRetryException::forActionRun($actionRun->id);
        }

        $this->record($actionRun, $result);
        $this->advance($run);

        return $actionRun->refresh();
    }

    /**
     * Put back on the queue the steps of running automations that nothing is working on: a pending
     * action whose job was lost (e.g. the queue was unreachable when it was dispatched), and runs
     * left "running" with no unfinished step. Each action is claimed atomically, so a duplicate
     * dispatch is harmless.
     *
     * @return int How many steps were recovered.
     */
    public function recoverStalled(int $olderThanMinutes): int
    {
        $cutoff = now()->subMinutes($olderThanMinutes);

        $actionRunIds = AutomationActionRun::query()
            ->where('status', AutomationActionRunStatus::Pending)
            ->where('updated_at', '<', $cutoff)
            ->whereHas('run', fn ($query) => $query->where('status', AutomationRunStatus::Running))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        if ($actionRunIds->isNotEmpty()) {
            // Touch first: the stalled step is leased, so the next sweep doesn't queue it again at once.
            AutomationActionRun::query()->whereIn('id', $actionRunIds)->update(['updated_at' => now()]);

            foreach ($actionRunIds as $id) {
                ExecuteAutomationActionJob::dispatch($id)->afterCommit();
            }
        }

        $runsWithoutSteps = AutomationRun::query()
            ->where('status', AutomationRunStatus::Running)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('actionRuns', fn ($query) => $query->whereIn('status', [AutomationActionRunStatus::Pending, AutomationActionRunStatus::Running]))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($runsWithoutSteps as $run) {
            DB::transaction(fn () => $this->advance($run));
        }

        if ($actionRunIds->isNotEmpty() || $runsWithoutSteps->isNotEmpty()) {
            Log::warning('Stalled automation steps were recovered.', ['event' => 'automation.steps_recovered', 'actions' => $actionRunIds->count(), 'runs' => $runsWithoutSteps->count()]);
        }

        return $actionRunIds->count() + $runsWithoutSteps->count();
    }

    /**
     * Record a failure for an action whose job gave up (retries exhausted, timeout).
     */
    public function markActionRunFailed(int $actionRunId, string $reason): void
    {
        $updated = AutomationActionRun::query()
            ->whereKey($actionRunId)
            ->whereIn('status', [AutomationActionRunStatus::Pending, AutomationActionRunStatus::Running])
            ->update([
                'status' => AutomationActionRunStatus::Failed,
                'error_message' => $reason,
                'result' => json_encode(['success' => false, 'status' => 'failed', 'message' => $reason, 'data' => []]),
                'executed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $actionRun = AutomationActionRun::find($actionRunId);
            Log::warning('Automation action failed after retries.', ['event' => 'automation.action.failed', 'automation_action_run_id' => $actionRunId, 'automation_run_id' => $actionRun->automation_run_id]);
            $this->advance($actionRun->run);
        }
    }

    /**
     * Resume waiting runs whose WAIT is over: each is handed to a job, which claims it.
     * The lease (resume_at moved forward) keeps a slow queue from receiving it twice.
     *
     * @return int How many were queued.
     */
    public function resumeDue(): int
    {
        $now = now();

        // Same shape as markDue: pick a bounded set under locks, then lease exactly those runs (see FollowUpProcessor).
        $ids = DB::transaction(function () use ($now) {
            $ids = collect(DB::select(
                'select id from automation_runs where status = ? and resume_at <= ? order by resume_at limit ? for update skip locked',
                [AutomationRunStatus::Waiting->value, $now, 200],
            ))->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($ids === []) {
                return [];
            }

            DB::table('automation_runs')
                ->whereIn('id', $ids)
                ->where('status', AutomationRunStatus::Waiting->value)
                ->update(['resume_at' => $now->copy()->addMinutes(10), 'updated_at' => $now]);

            return $ids;
        });

        foreach ($ids as $id) {
            ResumeAutomationRunJob::dispatch($id);
        }

        return count($ids);
    }

    /**
     * The WAIT is over: re-check the automation and its conditions with today's data, then
     * run the actions — or record why the run was skipped (e.g. "Customer already replied.").
     *
     * The claim and everything it leads to share one transaction: a worker that dies part way
     * leaves the run "waiting" (its lease expires and it is retried), never stuck "running".
     */
    public function resume(int $runId): ?AutomationRun
    {
        $run = DB::transaction(function () use ($runId) {
            $run = AutomationRun::query()->whereKey($runId)->where('status', AutomationRunStatus::Waiting)->lockForUpdate()->first();

            if ($run === null) {
                return null;
            }

            $run->forceFill(['status' => AutomationRunStatus::Running, 'resume_at' => null])->save();

            return $this->planResumedRun($run);
        });

        // Notify after commit, and only from the call that actually failed the run.
        if ($run?->status === AutomationRunStatus::Failed) {
            app(ActivityNotifications::class)->automationFailed($run->id);
        }

        return $run;
    }

    /**
     * Re-check a claimed run and either finish it (skipped or failed) or create its actions.
     */
    private function planResumedRun(AutomationRun $run): AutomationRun
    {
        $context = AutomationContext::fromArray($run->context ?? []);
        $automation = Automation::query()->forOrganization($run->organization_id)->with(['conditions', 'actions'])->find($run->automation_id);

        $skip = match (true) {
            $automation === null => 'The automation was deleted.',
            ! $automation->isActive() => 'The automation was '.strtolower($automation->status->label()).' while it was waiting.',
            ! config('automation.enabled') || ! Organization::whereKey($run->organization_id)->value('automations_enabled') => 'Automations are turned off for this organization.',
            ! $this->eventBelongsToOrganization($context) => 'The customer or conversation is no longer available.',
            default => null,
        };

        if ($skip !== null) {
            return $this->finish($run, AutomationRunStatus::Skipped, $skip);
        }

        try {
            $outcome = $this->conditions->explain($automation, $context);
        } catch (InvalidAutomationConditionException $e) {
            return $this->finish($run, AutomationRunStatus::Failed, $e->getMessage());
        }

        if (! $outcome['matched']) {
            return $this->finish($run, AutomationRunStatus::Skipped, $outcome['reason'], $outcome);
        }

        $run->forceFill(['condition_results' => $outcome])->save();
        $this->createActionRuns($run, $automation->actions);
        $this->advance($run);

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $conditions
     */
    private function finish(AutomationRun $run, AutomationRunStatus $status, ?string $reason, ?array $conditions = null): AutomationRun
    {
        $run->forceFill([
            'status' => $status,
            'completed_at' => $status === AutomationRunStatus::Skipped ? now() : null,
            'failed_at' => $status === AutomationRunStatus::Failed ? now() : null,
            'failure_reason' => $reason,
            'condition_results' => $conditions ?? $run->condition_results,
        ])->save();

        Log::info('Automation run finished without actions.', ['event' => 'automation.run.finished', 'automation_run_id' => $run->id, 'status' => $status->value]);

        return $run;
    }

    private function evaluateAutomation(Automation $automation, AutomationContext $context): ?AutomationRun
    {
        $maxDepth = (int) config('automation.limits.max_chain_depth');

        if ($context->depth >= $maxDepth) {
            Log::warning('Automation chain limit reached; run skipped.', ['event' => 'automation.chain_limit_reached', 'automation_id' => $automation->id, 'event_id' => $context->eventId, 'depth' => $context->depth]);

            return $this->createRun($automation, $context, AutomationRunStatus::Skipped, "Automation chain limit reached (depth {$maxDepth}).");
        }

        // WAIT first: conditions are checked when the wait is over, with the data of that moment.
        if ($automation->wait_minutes) {
            return $this->createRun($automation, $context, AutomationRunStatus::Waiting, resumeAt: now()->addMinutes($automation->wait_minutes));
        }

        try {
            $outcome = $this->conditions->explain($automation, $context);
        } catch (InvalidAutomationConditionException $e) {
            return $this->createRun($automation, $context, AutomationRunStatus::Failed, $e->getMessage());
        }

        // Not matching is the normal case (most replies aren't "ready to book"): nothing is recorded.
        if (! $outcome['matched']) {
            return null;
        }

        return $this->createRun($automation, $context, AutomationRunStatus::Running, conditions: $outcome);
    }

    /**
     * Create the run (and its action runs) once per automation and event.
     *
     * @param  array<string, mixed>|null  $conditions
     */
    private function createRun(Automation $automation, AutomationContext $context, AutomationRunStatus $status, ?string $reason = null, ?\DateTimeInterface $resumeAt = null, ?array $conditions = null): ?AutomationRun
    {
        try {
            // A savepoint: a duplicate insert must not abort an enclosing transaction.
            $run = DB::transaction(function () use ($automation, $context, $status, $reason, $resumeAt, $conditions) {
                $run = new AutomationRun;
                $run->forceFill([
                    'organization_id' => $automation->organization_id,
                    'automation_id' => $automation->id,
                    // Verified by eventBelongsToOrganization() before any run is created.
                    'conversation_id' => $context->conversationId,
                    'customer_id' => $context->customerId,
                    'event_type' => $context->triggerType,
                    'event_id' => $context->eventId,
                    'status' => $status,
                    'resume_at' => $resumeAt,
                    'depth' => $context->depth,
                    'context' => $context->toArray(),
                    'condition_results' => $conditions,
                    'started_at' => now(),
                    'completed_at' => $status === AutomationRunStatus::Skipped ? now() : null,
                    'failed_at' => $status === AutomationRunStatus::Failed ? now() : null,
                    'failure_reason' => $reason,
                ])->save();

                if ($status === AutomationRunStatus::Running) {
                    $this->createActionRuns($run, $automation->actions);
                    $this->advance($run);
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('Automation run skipped: this event was already handled.', ['event' => 'automation.run.duplicate', 'automation_id' => $automation->id, 'event_id' => $context->eventId]);

            return null;
        }

        if ($status === AutomationRunStatus::Failed) {
            app(ActivityNotifications::class)->automationFailed($run->id);
        }

        Log::info('Automation run created.', ['event' => 'automation.run.created',
            'organization_id' => $run->organization_id,
            'automation_id' => $automation->id,
            'automation_run_id' => $run->id,
            'event_id' => $context->eventId,
            'status' => $status->value,
            'depth' => $context->depth,
        ]);

        return $run;
    }

    /**
     * @param  iterable<AutomationAction>  $actions
     */
    private function createActionRuns(AutomationRun $run, iterable $actions): void
    {
        $limit = (int) config('automation.limits.max_actions_per_run');
        $position = 0;

        foreach ($actions as $action) {
            $overLimit = ++$position > $limit;

            $actionRun = new AutomationActionRun;
            $actionRun->forceFill([
                'automation_run_id' => $run->id,
                'automation_action_id' => $action->id,
                'action_type' => $action->getAttributes()['type'],
                'status' => $overLimit ? AutomationActionRunStatus::Skipped : AutomationActionRunStatus::Pending,
                'result' => $overLimit ? AutomationActionResult::skipped('Action limit reached for this run.', ['reason' => 'action_limit'])->toArray() : null,
                'executed_at' => $overLimit ? now() : null,
            ])->save();
        }

        if ($position > $limit) {
            Log::warning('Automation action limit reached; extra actions were skipped.', ['event' => 'automation.action.limit_reached', 'automation_run_id' => $run->id, 'limit' => $limit]);
        }
    }

    /**
     * Atomically move an action run to running. A crashed worker's run can be reclaimed once stale.
     */
    private function claim(int $actionRunId): ?AutomationActionRun
    {
        $claimed = AutomationActionRun::query()
            ->whereKey($actionRunId)
            ->where(fn ($query) => $query
                ->where('status', AutomationActionRunStatus::Pending)
                ->orWhere(fn ($query) => $query
                    ->where('status', AutomationActionRunStatus::Running)
                    ->where('updated_at', '<', now()->subSeconds((int) config('automation.retries.stale_after_seconds')))))
            ->update(['status' => AutomationActionRunStatus::Running, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        return $claimed === 0 ? null : AutomationActionRun::with('run')->find($actionRunId);
    }

    /**
     * Re-check everything that may have changed since the run was created. Returns a result to
     * record instead of executing, or null when the action may run.
     */
    private function guard(AutomationRun $run, AutomationActionRun $actionRun, AutomationContext $context): ?AutomationActionResult
    {
        $automation = Automation::find($run->automation_id);

        if ($automation === null || $automation->organization_id !== $run->organization_id || $context->organizationId !== $run->organization_id) {
            Log::warning('Automation action rejected: organization mismatch.', ['event' => 'automation.action.rejected', 'automation_action_run_id' => $actionRun->id]);

            return AutomationActionResult::failed('This action does not belong to the organization that triggered it.');
        }

        if (! config('automation.enabled') || ! Organization::whereKey($run->organization_id)->value('automations_enabled')) {
            return AutomationActionResult::skipped('Automations are turned off for this organization.', ['reason' => 'automations_disabled']);
        }

        if (! $automation->isActive()) {
            return AutomationActionResult::skipped('The automation is no longer active.', ['reason' => 'automation_inactive']);
        }

        if ($this->actionFor($run, $actionRun) === null) {
            return AutomationActionResult::skipped('The action was removed from the automation.', ['reason' => 'action_removed']);
        }

        if (! $this->eventBelongsToOrganization($context)) {
            return AutomationActionResult::failed('The customer or conversation does not belong to this organization.');
        }

        return null;
    }

    private function actionFor(AutomationRun $run, AutomationActionRun $actionRun): ?AutomationAction
    {
        return AutomationAction::query()
            ->where('automation_id', $run->automation_id)
            ->find($actionRun->automation_action_id);
    }

    /**
     * The event's customer and conversation must exist in its organization and belong together.
     */
    private function eventBelongsToOrganization(AutomationContext $context): bool
    {
        if ($context->customerId !== null && $context->customer() === null) {
            return false;
        }

        if ($context->conversationId === null) {
            return true;
        }

        $conversation = $context->conversation();

        return $conversation !== null && ($context->customerId === null || $conversation->customer_id === $context->customerId);
    }

    private function release(AutomationActionRun $actionRun): void
    {
        AutomationActionRun::query()
            ->whereKey($actionRun->id)
            ->where('status', AutomationActionRunStatus::Running)
            ->update(['status' => AutomationActionRunStatus::Pending, 'updated_at' => now()]);

        Log::warning('Automation action failed temporarily; it will be retried.', ['event' => 'automation.action.retry_scheduled', 'automation_action_run_id' => $actionRun->id]);
    }

    private function record(AutomationActionRun $actionRun, AutomationActionResult $result): void
    {
        $actionRun->forceFill([
            'status' => $result->status,
            'result' => $result->toArray(),
            'error_message' => $result->success ? null : $result->message,
            'executed_at' => now(),
        ])->save();

        Log::info('Automation action executed.', ['event' => 'automation.action.executed',
            'organization_id' => $actionRun->run->organization_id,
            'automation_run_id' => $actionRun->automation_run_id,
            'automation_action_run_id' => $actionRun->id,
            'action_type' => $actionRun->getAttributes()['action_type'],
            'status' => $result->status->value,
        ]);
    }

    /**
     * Queue the run's next pending action, or close the run when nothing is left.
     */
    private function advance(AutomationRun $run): void
    {
        $remaining = $run->actionRuns()
            ->whereIn('status', [AutomationActionRunStatus::Pending, AutomationActionRunStatus::Running])
            ->orderBy('id')
            ->get(['id', 'status']);

        $next = $remaining->first();

        if ($next !== null) {
            if ($next->status === AutomationActionRunStatus::Pending) {
                ExecuteAutomationActionJob::dispatch($next->id)->afterCommit();
            }

            return;
        }

        $failed = $run->actionRuns()->where('status', AutomationActionRunStatus::Failed)->count();

        $finished = AutomationRun::query()
            ->whereKey($run->id)
            ->where('status', AutomationRunStatus::Running)
            ->update($failed > 0
                ? ['status' => AutomationRunStatus::Failed, 'failed_at' => now(), 'failure_reason' => $failed === 1 ? '1 action failed.' : "{$failed} actions failed.", 'updated_at' => now()]
                : ['status' => AutomationRunStatus::Completed, 'completed_at' => now(), 'updated_at' => now()]);

        // Only the call that actually finished the run notifies (once).
        if ($finished > 0 && $failed > 0) {
            app(ActivityNotifications::class)->automationFailed($run->id);
        }
    }
}

<?php

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Events\CustomerReplyClassified;
use App\Jobs\EvaluateAutomationJob;
use App\Jobs\ExecuteAutomationActionJob;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->organization = $this->admin->organization;
    $this->customer = Customer::factory()->for($this->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
    $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id, 'subject' => 'AC Replacement']);
    // Jobs are run by hand below, so each step of an execution can be shown.
    Queue::fake();
});

/**
 * An active "ready to book" automation that creates one task.
 */
function reliabilityAutomation(User $owner, array $overrides = []): Automation
{
    $automation = Automation::factory()->active()->create(array_merge([
        'organization_id' => $owner->organization_id,
        'trigger_type' => AutomationTriggerType::CustomerReplyClassified,
    ], $overrides));
    $automation->conditions()->create(['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'ready_to_book']);
    $automation->actions()->create(['type' => AutomationActionType::CreateTask, 'configuration' => ['title' => 'Call {customer_name}'], 'sort_order' => 0]);

    return $automation;
}

function reliabilityEvent(int $organizationId, int $conversationId, int $customerId, int $classificationId = 1): CustomerReplyClassified
{
    return new CustomerReplyClassified($organizationId, $classificationId, $conversationId, $customerId, $classificationId, CustomerReplyIntent::ReadyToBook, 0.95);
}

function reliabilityActionRun(): AutomationActionRun
{
    return AutomationActionRun::query()->orderBy('id')->firstOrFail();
}

beforeEach(function () {
    $this->engine = app(AutomationEngine::class);
    $this->event = reliabilityEvent($this->organization->id, $this->conversation->id, $this->customer->id);
});

test('the same event evaluated twice creates one run and one task', function () {
    reliabilityAutomation($this->admin);

    $this->engine->evaluate($this->event);
    $this->engine->evaluate($this->event);

    expect(AutomationRun::count())->toBe(1);

    $this->engine->executeActionRun(reliabilityActionRun()->id);
    $this->engine->executeActionRun(reliabilityActionRun()->id);

    expect(Task::count())->toBe(1);
});

test('a duplicate evaluation job for the same event changes nothing', function () {
    reliabilityAutomation($this->admin);

    (new EvaluateAutomationJob($this->event))->handle($this->engine);
    (new EvaluateAutomationJob($this->event))->handle($this->engine);

    expect(AutomationRun::count())->toBe(1)->and(AutomationActionRun::count())->toBe(1);
});

test('a step another worker is already running is not executed a second time', function () {
    reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);
    $actionRun = reliabilityActionRun();

    // Worker A holds the step: it was claimed a moment ago.
    AutomationActionRun::whereKey($actionRun->id)->update(['status' => AutomationActionRunStatus::Running, 'updated_at' => now()]);

    expect($this->engine->executeActionRun($actionRun->id))->toBeNull()
        ->and(Task::count())->toBe(0);
});

test('a step whose worker crashed is reclaimed once it is stale, and runs exactly once', function () {
    reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);
    $actionRun = reliabilityActionRun();
    AutomationActionRun::whereKey($actionRun->id)->update(['status' => AutomationActionRunStatus::Running, 'updated_at' => now()->subMinutes(5)]);

    $this->engine->executeActionRun($actionRun->id);
    $this->engine->executeActionRun($actionRun->id);

    expect(Task::count())->toBe(1)
        ->and(reliabilityActionRun()->status)->toBe(AutomationActionRunStatus::Completed);
});

test('a worker that crashed after doing the work does not repeat it when the step is retried', function () {
    reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);
    $actionRun = reliabilityActionRun();

    // The task was created, then the worker died before recording the step.
    $this->engine->executeActionRun($actionRun->id);
    AutomationActionRun::whereKey($actionRun->id)->update(['status' => AutomationActionRunStatus::Running, 'updated_at' => now()->subMinutes(5)]);

    $this->engine->executeActionRun($actionRun->id);

    expect(Task::count())->toBe(1)
        ->and(reliabilityActionRun()->result['data']['reason'] ?? null)->toBe('already_exists');
});

test('a disabled automation does not execute its pending steps', function () {
    $automation = reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);

    $automation->forceFill(['status' => 'paused'])->save();
    $this->engine->executeActionRun(reliabilityActionRun()->id);

    expect(Task::count())->toBe(0)
        ->and(reliabilityActionRun()->status)->toBe(AutomationActionRunStatus::Skipped);
});

test('turning automations off for the organization stops pending steps too', function () {
    reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);

    $this->organization->forceFill(['automations_enabled' => false])->save();
    $this->engine->executeActionRun(reliabilityActionRun()->id);

    expect(Task::count())->toBe(0)->and(reliabilityActionRun()->status)->toBe(AutomationActionRunStatus::Skipped);
});

test('a job for a step whose automation was deleted is ignored safely', function () {
    $automation = reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);
    $actionRunId = reliabilityActionRun()->id;

    $automation->delete();

    expect($this->engine->executeActionRun($actionRunId))->toBeNull()
        ->and(Task::count())->toBe(0);
    (new ExecuteAutomationActionJob($actionRunId))->handle($this->engine);
    (new ExecuteAutomationActionJob(987654))->handle($this->engine);
});

test('a wait is resumed once even when two workers resume it', function () {
    $automation = reliabilityAutomation($this->admin, ['wait_minutes' => 60]);
    $this->engine->evaluate($this->event);
    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Waiting);

    $this->travel(61)->minutes();

    expect($this->engine->resume($run->id))->not->toBeNull()
        ->and($this->engine->resume($run->id))->toBeNull()
        ->and(AutomationActionRun::count())->toBe(1);
});

test('a waiting run is skipped, not executed, when its automation was paused during the wait', function () {
    $automation = reliabilityAutomation($this->admin, ['wait_minutes' => 60]);
    $this->engine->evaluate($this->event);
    $automation->forceFill(['status' => 'paused'])->save();

    $this->travel(61)->minutes();
    $run = $this->engine->resume(AutomationRun::sole()->id);

    expect($run->status)->toBe(AutomationRunStatus::Skipped)
        ->and(Task::count())->toBe(0);
});

test('a resume job for a run that no longer exists does nothing', function () {
    expect($this->engine->resume(987654))->toBeNull();
});

test('an event that names another organization\'s customer creates no run', function () {
    $otherUser = User::factory()->admin()->create();
    $otherCustomer = Customer::factory()->for($otherUser->organization)->create();
    $otherConversation = Conversation::factory()->for($otherCustomer)->create(['organization_id' => $otherUser->organization_id]);
    reliabilityAutomation($this->admin);
    reliabilityAutomation($otherUser);

    // Claimed as ours, but the conversation belongs to another organization.
    $this->engine->evaluate(reliabilityEvent($this->organization->id, $otherConversation->id, $otherCustomer->id, 9));

    expect(AutomationRun::count())->toBe(0)->and(Task::count())->toBe(0);
});

test('a pending step left behind by a lost queue message is queued again', function () {
    reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);
    $actionRun = reliabilityActionRun();
    DB::table('automation_action_runs')->where('id', $actionRun->id)->update(['updated_at' => now()->subMinutes(20)]);
    AutomationRun::sole()->forceFill(['status' => AutomationRunStatus::Running, 'updated_at' => now()->subMinutes(20)])->save();

    expect($this->engine->recoverStalled(15))->toBe(1);

    Queue::assertPushed(ExecuteAutomationActionJob::class, fn (ExecuteAutomationActionJob $job) => $job->actionRunId === $actionRun->id);

    // Queued again only once per window.
    expect($this->engine->recoverStalled(15))->toBe(0);
});

test('a run left "running" after all its steps finished is completed by the recovery sweep', function () {
    reliabilityAutomation($this->admin);
    $this->engine->evaluate($this->event);
    AutomationActionRun::query()->update(['status' => AutomationActionRunStatus::Completed, 'executed_at' => now()]);
    AutomationRun::sole()->forceFill(['status' => AutomationRunStatus::Running, 'updated_at' => now()->subMinutes(20)])->save();

    $this->engine->recoverStalled(15);

    expect(AutomationRun::sole()->status)->toBe(AutomationRunStatus::Completed);
});

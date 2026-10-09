<?php

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Enums\MessageDirection;
use App\Events\EstimateSent;
use App\Livewire\Automations\AutomationForm;
use App\Livewire\Automations\AutomationLogs;
use App\Livewire\Automations\ShowAutomation;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationTemplates;
use App\Services\Automation\EmailTemplateRenderer;
use App\Services\Automation\Registry\TriggerRegistry;
use App\Services\Estimates\EstimateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    fakeEmailProvider();
    [$this->admin, $this->john, $this->conversation] = estimateBusiness();
    $this->builder = app(AutomationBuilder::class);

    // Saves (and by default activates) an automation through the builder service.
    $this->rule = function (array $input, bool $activate = true): Automation {
        $automation = $this->builder->save($this->admin->organization, $this->admin, $input + [
            'name' => 'Test rule',
            'trigger_type' => 'estimate_sent',
            'conditions' => [],
            'actions' => [['type' => 'create_task', 'configuration' => ['title' => 'Call {{customer.name}}']]],
        ]);

        if ($activate) {
            $this->builder->activate($automation, $this->admin);
        }

        return $automation->refresh();
    };

    // "Does the automation run for a sent estimate?" — true when a run was recorded.
    $this->runsFor = function (array $conditions, string $match = 'all'): bool {
        AutomationRun::query()->delete();
        Automation::query()->delete();
        ($this->rule)(['conditions' => $conditions, 'condition_match' => $match]);
        sentEstimate($this->admin, $this->john);

        return AutomationRun::exists();
    };
});

function validationErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// Creation

test('1. an automation can be created through the step builder', function () {
    Livewire::actingAs($this->admin)->test(AutomationForm::class)
        ->set('name', 'Follow up after estimate')->call('next')->assertSet('step', 2)
        ->set('triggerType', 'estimate_sent')->call('next')->assertSet('step', 3)
        ->set('wait', '4320')->call('addCondition')
        ->set('conditions.0', ['type' => 'customer_replied', 'operator' => 'is_false', 'value' => ''])->call('next')->assertSet('step', 4)
        ->call('addAction')->set('actions.0.type', 'create_task')->set('actions.0.configuration', ['title' => 'Call {{customer.name}}', 'priority' => 'high'])
        ->call('next')->assertSet('step', 5)->assertHasNoErrors()
        ->call('saveDraft')->assertRedirect();

    $automation = Automation::sole();
    expect($automation->name)->toBe('Follow up after estimate')
        ->and($automation->organization_id)->toBe($this->admin->organization_id)
        ->and($automation->wait_minutes)->toBe(4320)
        ->and($automation->conditions)->toHaveCount(1)
        ->and($automation->actions->sole()->configuration['title'])->toBe('Call {{customer.name}}')
        ->and($automation->history()->pluck('action')->all())->toBe(['created']);
});

test('2. a new automation starts as a draft', function () {
    $automation = ($this->rule)([], activate: false);

    expect($automation->status)->toBe(AutomationStatus::Draft);
});

test('3. the name is required and limited, but not unique', function () {
    expect(validationErrors(fn () => ($this->rule)(['name' => '  '], false)))->toHaveKey('name')
        ->and(validationErrors(fn () => ($this->rule)(['name' => str_repeat('a', 101)], false)))->toHaveKey('name');

    ($this->rule)(['name' => 'Same name'], false);
    ($this->rule)(['name' => 'Same name'], false);
    expect(Automation::where('name', 'Same name')->count())->toBe(2);

    Livewire::actingAs($this->admin)->test(AutomationForm::class)->call('next')->assertHasErrors('name')->assertSet('step', 1);
});

test('4. the trigger must be one the engine supports', function () {
    expect(validationErrors(fn () => ($this->rule)(['trigger_type' => 'invoice_paid'], false)))->toHaveKey('trigger_type')
        ->and(validationErrors(fn () => ($this->rule)(['trigger_type' => ''], false)))->toHaveKey('trigger_type');

    // Only executable triggers are offered.
    expect(collect(TriggerRegistry::available())->every(fn ($d) => $d->available))->toBeTrue()
        ->and(collect(TriggerRegistry::available())->map(fn ($d) => $d->key())->all())->toContain(
            'customer_created', 'customer_reply_classified', 'estimate_created', 'estimate_sent', 'estimate_viewed', 'estimate_accepted',
            'estimate_declined', 'estimate_expired', 'follow_up_due', 'follow_up_completed', 'conversation_closed', 'conversation_reopened',
        );
});

test('5. conditions are validated by type, operator, value and trigger', function () {
    $bad = [
        [['type' => 'estimate_total', 'operator' => 'contains', 'value' => '1000']],      // operator not for numbers
        [['type' => 'estimate_total', 'operator' => 'greater_than', 'value' => 'lots']],            // not a number
        [['type' => 'estimate_status_equals', 'operator' => 'equals', 'value' => 'paid']], // not an option
        [['type' => 'estimate_valid_until', 'operator' => 'before', 'value' => '31/12/2026']],
        [['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'interested']],   // no AI reply on "estimate sent"
        [['type' => 'php_code', 'operator' => 'equals', 'value' => 'x']],
    ];

    foreach ($bad as $conditions) {
        expect(validationErrors(fn () => ($this->rule)(['conditions' => $conditions], false)))->toHaveKey('conditions.0');
    }

    expect(validationErrors(fn () => ($this->rule)(['conditions' => [], 'condition_match' => 'some'], false)))->toHaveKey('condition_match');
});

test('6. actions are validated by type, fields and trigger', function () {
    $bad = [
        ['type' => 'run_webhook', 'configuration' => []],
        ['type' => 'create_task', 'configuration' => ['title' => '']],
        ['type' => 'create_task', 'configuration' => ['title' => 'Call', 'priority' => 'urgent!!']],
        ['type' => 'send_email', 'configuration' => ['subject' => 'Hi', 'body' => 'Hello {{php_code}}']],
        ['type' => 'update_conversation_status', 'configuration' => ['status' => 'deleted']],
    ];

    foreach ($bad as $action) {
        expect(validationErrors(fn () => ($this->rule)(['actions' => [$action]], false)))->toHaveKey('actions.0');
    }

    // A follow-up trigger can't create another follow-up.
    expect(validationErrors(fn () => ($this->rule)(['trigger_type' => 'follow_up_due', 'actions' => [
        ['type' => 'schedule_follow_up', 'configuration' => ['kind' => 'reminder', 'delay_days' => 3, 'title' => 'Again']],
    ]], false)))->toHaveKey('actions.0');
});

// Conditions (evaluated on a real "estimate sent": AC Installation, $2,750, valid 30 days, John has an email)

test('7. string conditions work', function () {
    expect(($this->runsFor)([['type' => 'estimate_title', 'operator' => 'contains', 'value' => 'installation']]))->toBeTrue()
        ->and(($this->runsFor)([['type' => 'estimate_title', 'operator' => 'equals', 'value' => 'Furnace repair']]))->toBeFalse();
});

test('8. numeric conditions work', function () {
    expect(($this->runsFor)([['type' => 'estimate_total', 'operator' => 'greater_than', 'value' => '1000']]))->toBeTrue()
        ->and(($this->runsFor)([['type' => 'estimate_total', 'operator' => 'less_than', 'value' => '1000']]))->toBeFalse();
});

test('9. enum conditions work', function () {
    expect(($this->runsFor)([['type' => 'estimate_status_equals', 'operator' => 'equals', 'value' => 'sent']]))->toBeTrue()
        ->and(($this->runsFor)([['type' => 'estimate_status_equals', 'operator' => 'equals', 'value' => 'accepted']]))->toBeFalse();
});

test('10. boolean conditions work', function () {
    expect(($this->runsFor)([['type' => 'customer_has_email', 'operator' => 'is_true', 'value' => '']]))->toBeTrue()
        ->and(($this->runsFor)([['type' => 'customer_has_phone', 'operator' => 'is_true', 'value' => '']]))->toBe($this->john->phone !== null && $this->john->phone !== '');
});

test('11. date conditions work', function () {
    expect(($this->runsFor)([['type' => 'estimate_valid_until', 'operator' => 'after', 'value' => 'today']]))->toBeTrue()
        ->and(($this->runsFor)([['type' => 'estimate_valid_until', 'operator' => 'before', 'value' => now()->toDateString()]]))->toBeFalse();
});

test('12. ALL conditions must all match', function () {
    $pass = ['type' => 'estimate_total', 'operator' => 'greater_than', 'value' => '1000'];
    $fail = ['type' => 'estimate_title', 'operator' => 'equals', 'value' => 'Furnace repair'];

    expect(($this->runsFor)([$pass, $pass], 'all'))->toBeTrue()
        ->and(($this->runsFor)([$pass, $fail], 'all'))->toBeFalse();
});

test('13. ANY condition may match', function () {
    $pass = ['type' => 'estimate_total', 'operator' => 'greater_than', 'value' => '1000'];
    $fail = ['type' => 'estimate_title', 'operator' => 'equals', 'value' => 'Furnace repair'];

    expect(($this->runsFor)([$fail, $pass], 'any'))->toBeTrue()
        ->and(($this->runsFor)([$fail, $fail], 'any'))->toBeFalse();
});

// Actions

test('14. the send email action emails the customer from the verified sender', function () {
    allowAutomaticFollowUpEmail($this->admin->organization);
    ($this->rule)(['actions' => [['type' => 'send_email', 'requires_approval' => false, 'configuration' => ['subject' => 'About {{estimate.number}}', 'body' => 'Hi {{customer.first_name}}']]]]);

    $estimate = sentEstimate($this->admin, $this->john);

    $email = Message::where('direction', MessageDirection::Outbound)->where('subject', "About {$estimate->estimate_number}")->sole();
    expect($email->from_address)->toBe('sales@example.com')
        ->and($email->to_address)->toBe('john@example.com')
        ->and($email->body_text)->toBe('Hi John')
        ->and(AutomationRun::sole()->status)->toBe(AutomationRunStatus::Completed);
});

test('15. the create follow-up action schedules one follow-up', function () {
    ($this->rule)(['actions' => [['type' => 'schedule_follow_up', 'configuration' => ['kind' => 'reminder', 'delay_days' => '3', 'title' => 'Follow up on {{estimate.number}}']]]]);

    $estimate = sentEstimate($this->admin, $this->john);

    $followUp = FollowUp::sole();
    expect($followUp->customer_id)->toBe($this->john->id)
        ->and($followUp->estimate_id)->toBe($estimate->id)
        ->and($followUp->due_at->isSameDay(now()->addDays(3)))->toBeTrue();
});

test('16. the create task action creates a task for the owner', function () {
    ($this->rule)(['actions' => [['type' => 'create_task', 'configuration' => ['title' => 'Call {{customer.name}}', 'priority' => 'high', 'assign_to' => 'owner']]]]);

    sentEstimate($this->admin, $this->john);

    $task = Task::sole();
    expect($task->title)->toBe('Call John Smith')
        ->and($task->organization_id)->toBe($this->admin->organization_id)
        ->and($task->assigned_to)->toBe($this->admin->id);
});

test('17. the notification action notifies the business in the app', function () {
    ($this->rule)(['actions' => [['type' => 'notify_user', 'configuration' => ['message' => '{{customer.name}} got estimate {{estimate.number}}.']]]]);

    $estimate = sentEstimate($this->admin, $this->john);

    $notification = $this->admin->notifications()->sole();
    expect($notification->data['message'])->toBe("John Smith got estimate {$estimate->estimate_number}.");
});

// Variables

test('18. valid variables render correctly', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    $text = app(EmailTemplateRenderer::class)->render(
        'Hi {{customer.first_name}}, estimate {{estimate.number}} from {{business.name}}.',
        $this->john, $this->admin->organization, $estimate,
    );

    expect($text)->toBe("Hi John, estimate {$estimate->estimate_number} from Dallas HVAC.");
});

test('19. invalid variables prevent activation', function () {
    $automation = ($this->rule)([], activate: false);
    $automation->actions()->update(['configuration' => ['title' => 'Call {{customer.shoe_size}}']]);

    expect(fn () => $this->builder->activate($automation->refresh(), $this->admin))->toThrow(ValidationException::class)
        ->and($automation->refresh()->status)->toBe(AutomationStatus::Draft);
});

test('20. variables must be available for the trigger', function () {
    // "Customer created" has no estimate.
    $errors = validationErrors(fn () => ($this->rule)(['trigger_type' => 'customer_created', 'actions' => [
        ['type' => 'create_task', 'configuration' => ['title' => 'Call about {{estimate.number}}']],
    ]], false));

    expect($errors['actions.0'][0] ?? '')->toContain('{{estimate.number}}');
    expect(($this->rule)(['trigger_type' => 'customer_created'], false)->trigger_type->value)->toBe('customer_created');
});

// Activation

test('21. a valid automation can be activated', function () {
    $automation = ($this->rule)([], activate: false);

    $this->builder->activate($automation, $this->admin);

    expect($automation->refresh()->status)->toBe(AutomationStatus::Active)
        ->and($automation->history()->pluck('action')->all())->toContain('activated');
});

test('22. an invalid automation cannot be activated', function () {
    $noActions = ($this->rule)(['actions' => []], activate: false);
    expect($this->builder->activationErrors($noActions))->toHaveKey('actions');

    // Customer email without automatic email / a verified sender.
    $email = ($this->rule)(['actions' => [['type' => 'send_email', 'configuration' => ['subject' => 'Hi', 'body' => 'Hello']]]], activate: false);
    $this->admin->organization->emailConnections()->delete();
    expect($this->builder->activationErrors($email->refresh()))->toHaveKey('actions');

    expect(fn () => $this->builder->activate($noActions, $this->admin))->toThrow(ValidationException::class)
        ->and(Automation::where('status', AutomationStatus::Active)->count())->toBe(0);
});

test('23. a draft automation does not execute', function () {
    ($this->rule)([], activate: false);

    sentEstimate($this->admin, $this->john);

    expect(AutomationRun::count())->toBe(0)->and(Task::count())->toBe(0);
});

test('24. a paused automation does not execute, even when it was waiting', function () {
    $automation = ($this->rule)(['wait_minutes' => 60]);
    sentEstimate($this->admin, $this->john);
    $this->builder->pause($automation, $this->admin);
    sentEstimate($this->admin, $this->john);

    $this->travel(61)->minutes();
    app(AutomationEngine::class)->resumeDue();

    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Skipped)
        ->and($run->failure_reason)->toBe('The automation was paused while it was waiting.')
        ->and(Task::count())->toBe(0);
});

test('25. an archived automation does not execute and cannot be activated', function () {
    $automation = ($this->rule)([]);
    $this->builder->archive($automation, $this->admin);

    sentEstimate($this->admin, $this->john);

    expect(AutomationRun::count())->toBe(0)
        ->and($this->builder->activationErrors($automation->refresh()))->toHaveKey('status');
});

// Safety

test('26. a customer reply stops an obsolete follow-up', function () {
    ($this->rule)(['wait_minutes' => 4320, 'conditions' => [['type' => 'customer_replied', 'operator' => 'is_false', 'value' => '']]]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->travel(1)->day();
    customerReply($estimate->conversation, 'Thanks, we will think about it.');
    $this->travel(2)->days();
    $this->travel(1)->minute();
    $this->artisan('automations:resume-waiting')->assertSuccessful();

    expect(AutomationRun::sole()->failure_reason)->toBe('Customer already replied.')
        ->and(Task::count())->toBe(0);
});

test('27. the same event never runs an automation twice', function () {
    ($this->rule)([]);
    $estimate = sentEstimate($this->admin, $this->john);

    $event = new EstimateSent($estimate->organization_id, $estimate->id, $estimate->customer_id, $estimate->conversation_id);
    app(AutomationEngine::class)->evaluate($event);
    event($event);

    expect(AutomationRun::count())->toBe(1)->and(Task::count())->toBe(1);
});

/**
 * Simulate a worker that died after the action ran but before it was recorded: the queue
 * retries the same action run once it is stale.
 */
function retryActionRun(AutomationActionRun $actionRun): void
{
    DB::table('automation_action_runs')->where('id', $actionRun->id)->update([
        'status' => AutomationActionRunStatus::Running->value,
        'updated_at' => now()->subHour(),
    ]);

    app(AutomationEngine::class)->executeActionRun($actionRun->id);
}

test('28. a queue retry does not email the customer twice', function () {
    allowAutomaticFollowUpEmail($this->admin->organization);
    ($this->rule)(['actions' => [['type' => 'send_email', 'requires_approval' => false, 'configuration' => ['subject' => 'Checking in', 'body' => 'Hi {{customer.first_name}}']]]]);
    sentEstimate($this->admin, $this->john);

    retryActionRun(AutomationActionRun::sole());

    expect(Message::where('subject', 'Checking in')->count())->toBe(1)
        ->and(AutomationActionRun::sole()->result['data']['reason'] ?? null)->toBe('already_exists');
});

test('29. a queue retry does not create a second follow-up', function () {
    ($this->rule)(['actions' => [['type' => 'schedule_follow_up', 'configuration' => ['kind' => 'reminder', 'delay_days' => '3', 'title' => 'Follow up']]]]);
    sentEstimate($this->admin, $this->john);

    retryActionRun(AutomationActionRun::sole());

    expect(FollowUp::count())->toBe(1);
});

// Organization isolation

test('30. organization A cannot see organization B automations', function () {
    [$other] = estimateBusiness('Other Co');
    $theirs = Automation::factory()->active()->create(['organization_id' => $other->organization_id, 'name' => 'Their secret rule']);

    $this->actingAs($this->admin)->get('/automations')->assertOk()->assertDontSee('Their secret rule');
    $this->actingAs($this->admin)->get("/automations/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/automations/{$theirs->id}/edit")->assertNotFound();
    Livewire::actingAs($this->admin)->test(ShowAutomation::class, ['automationId' => $theirs->id])->assertNotFound();
    expect(fn () => $this->builder->save($this->admin->organization, $this->admin, $this->builder->toInput($theirs), $theirs))->toThrow(Exception::class);
});

test('31. organization A events never execute organization B automations', function () {
    [$other, $otherCustomer] = estimateBusiness('Other Co');
    ($this->rule)([]);

    sentEstimate($other, $otherCustomer);
    // A forged event that mixes organizations is rejected as well.
    $estimate = sentEstimate($other, $otherCustomer);
    app(AutomationEngine::class)->evaluate(new EstimateSent($this->admin->organization_id, $estimate->id, $otherCustomer->id, $estimate->conversation_id));

    expect(Task::count())->toBe(0)
        ->and(AutomationRun::where('status', AutomationRunStatus::Completed)->count())->toBe(0);
});

test('32. organization A cannot view organization B logs', function () {
    [$other, $otherCustomer] = estimateBusiness('Other Co');
    $theirs = Automation::factory()->active()->create(['organization_id' => $other->organization_id]);
    $run = AutomationRun::query()->forceCreate(['organization_id' => $other->organization_id, 'automation_id' => $theirs->id, 'customer_id' => $otherCustomer->id,
        'event_type' => 'estimate_sent', 'event_id' => 'estimate:1', 'status' => 'completed']);

    $this->actingAs($this->admin)->get("/automations/{$theirs->id}/logs")->assertNotFound();
    $this->actingAs($this->admin)->get("/automations/{$theirs->id}/logs/{$run->id}")->assertNotFound();

    // Their run under our automation's URL is not found either.
    $ours = ($this->rule)([], activate: false);
    $this->actingAs($this->admin)->get("/automations/{$ours->id}/logs/{$run->id}")->assertNotFound();
});

// Logs

test('33. a successful execution is logged with its conditions', function () {
    $automation = ($this->rule)(['conditions' => [['type' => 'estimate_total', 'operator' => 'greater_than', 'value' => '1000']]]);
    sentEstimate($this->admin, $this->john);

    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Completed)
        ->and($run->customer_id)->toBe($this->john->id)
        ->and($run->condition_results['matched'])->toBeTrue()
        ->and($run->condition_results['results'][0]['passed'])->toBeTrue();

    Livewire::actingAs($this->admin)->test(AutomationLogs::class, ['automationId' => $automation->id])
        ->assertSee('Success')->assertSee('John Smith');
});

test('34. a failed execution is logged with the reason', function () {
    $member = User::factory()->for($this->admin->organization)->create();
    $automation = ($this->rule)(['actions' => [['type' => 'create_task', 'configuration' => ['title' => 'Call', 'assign_to' => (string) $member->id]]]]);
    [$other] = estimateBusiness('Other Co');
    $member->forceFill(['organization_id' => $other->organization_id])->save(); // left the business

    sentEstimate($this->admin, $this->john);

    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Failed)
        ->and($run->actionRuns->sole()->status)->toBe(AutomationActionRunStatus::Failed)
        ->and($run->actionRuns->sole()->error_message)->toBe('The assigned user is not a member of this organization.');

    Livewire::actingAs($this->admin)->test(AutomationLogs::class, ['automationId' => $automation->id, 'runId' => $run->id])
        ->assertSee('Failed')->assertSee('The assigned user is not a member of this organization.');
});

test('35. a skipped execution is logged with the reason', function () {
    $automation = ($this->rule)(['wait_minutes' => 60, 'conditions' => [['type' => 'estimate_status_equals', 'operator' => 'equals', 'value' => 'sent']]]);
    $estimate = sentEstimate($this->admin, $this->john);
    app(EstimateService::class)->accept($estimate);

    $this->travel(61)->minutes();
    app(AutomationEngine::class)->resumeDue();

    $run = AutomationRun::sole();
    expect($run->status)->toBe(AutomationRunStatus::Skipped)
        ->and($run->failure_reason)->not->toBeEmpty()
        ->and($run->condition_results['matched'])->toBeFalse();

    Livewire::actingAs($this->admin)->test(AutomationLogs::class, ['automationId' => $automation->id, 'status' => 'skipped'])
        ->assertSee('Skipped');
});

test('36. each action result is logged', function () {
    ($this->rule)(['actions' => [
        ['type' => 'create_task', 'configuration' => ['title' => 'Call {{customer.name}}']],
        ['type' => 'add_customer_tag', 'configuration' => ['tag' => 'estimate-sent']],
        ['type' => 'notify_user', 'configuration' => ['message' => 'Estimate sent to {{customer.name}}.']],
    ]]);

    sentEstimate($this->admin, $this->john);

    $actionRuns = AutomationRun::sole()->actionRuns()->orderBy('id')->get();
    expect($actionRuns->pluck('action_type')->map(fn ($t) => $t->value)->all())->toBe(['create_task', 'add_customer_tag', 'notify_user'])
        ->and($actionRuns->every(fn ($r) => $r->status === AutomationActionRunStatus::Completed && $r->executed_at !== null))->toBeTrue()
        ->and($actionRuns->every(fn ($r) => filled($r->result['message'] ?? null)))->toBeTrue();
});

// Templates

test('37. a starter template is installed as a draft', function () {
    foreach (AutomationTemplates::STARTERS as $key) {
        $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, $key);

        expect($automation->status)->toBe(AutomationStatus::Draft)
            ->and($automation->organization_id)->toBe($this->admin->organization_id)
            ->and($automation->actions)->not->toBeEmpty();
    }

    expect(Automation::pluck('name')->all())->toContain('Follow up after estimate', 'Ready to book alert', 'Estimate accepted', 'Interested customer follow-up');
});

test('38. a starter template never activates itself', function () {
    app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, 'estimate_accepted');
    $estimate = sentEstimate($this->admin, $this->john);
    app(EstimateService::class)->accept($estimate);

    expect(Automation::sole()->status)->toBe(AutomationStatus::Draft)
        ->and(AutomationRun::count())->toBe(0)
        ->and(Task::count())->toBe(0);
});

// Builder extras

test('duplicate creates a draft copy and archive keeps the history', function () {
    $automation = ($this->rule)(['name' => 'Ready to book']);

    $copy = $this->builder->duplicate($automation, $this->admin);
    $this->builder->archive($automation, $this->admin);
    $this->builder->restore($automation, $this->admin);

    expect($copy->name)->toBe('Copy of Ready to book')
        ->and($copy->status)->toBe(AutomationStatus::Draft)
        ->and($copy->actions)->toHaveCount(1)
        ->and($automation->refresh()->status)->toBe(AutomationStatus::Draft)
        ->and($automation->history()->pluck('action')->all())->toContain('created', 'activated', 'archived', 'restored');
});

test('a test run previews the actions without side effects', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    $before = [Task::count(), FollowUp::count(), Message::count(), AutomationRun::count(), DB::table('notifications')->count()];

    $component = Livewire::actingAs($this->admin)->test(AutomationForm::class)
        ->set('name', 'Preview')->call('next')
        ->set('triggerType', 'estimate_sent')->call('next')->call('next')
        ->set('actions', [['type' => 'create_task', 'configuration' => ['title' => 'Call {{customer.name}}'], 'requires_approval' => false]])->call('next')
        ->assertSet('step', 5)
        ->set('testSample', "estimate:{$estimate->id}")->call('runTest')->assertHasNoErrors();

    expect($component->get('testReport'))->not->toBeNull()
        ->and([Task::count(), FollowUp::count(), Message::count(), AutomationRun::count(), DB::table('notifications')->count()])->toBe($before)
        ->and(Customer::count())->toBe(1);
    $component->assertSee('Call John Smith');
});

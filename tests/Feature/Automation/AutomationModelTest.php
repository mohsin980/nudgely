<?php

namespace Tests\Feature\Automation;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationModelTest extends TestCase
{
    use RefreshDatabase;

    private function automation(?Organization $organization = null): Automation
    {
        $organization ??= Organization::factory()->create();
        $creator = User::factory()->admin()->for($organization)->create();

        $automation = new Automation(['name' => 'Customer Ready to Book', 'status' => AutomationStatus::Active, 'trigger_type' => AutomationTriggerType::CustomerReplyClassified]);
        $automation->forceFill(['organization_id' => $organization->id, 'created_by' => $creator->id])->save();

        return $automation;
    }

    private function createRun(Automation $automation, ?string $eventId = 'classification:1', ?int $organizationId = null): AutomationRun
    {
        $run = new AutomationRun;
        $run->forceFill([
            'automation_id' => $automation->id,
            'organization_id' => $organizationId ?? $automation->organization_id,
            'event_type' => AutomationTriggerType::CustomerReplyClassified,
            'event_id' => $eventId,
            'status' => AutomationRunStatus::Running,
            'started_at' => now(),
        ])->save();

        return $run;
    }

    // Structure

    public function test_automation_belongs_to_an_organization_and_its_creator(): void
    {
        $automation = $this->automation();

        $this->assertTrue($automation->organization->is($automation->organization()->first()));
        $this->assertTrue($automation->organization->automations->contains($automation));
        $this->assertSame($automation->created_by, $automation->creator->id);
        $this->assertNull($automation->updater);
        $this->assertSame(AutomationStatus::Active, $automation->fresh()->status);
        $this->assertSame(AutomationTriggerType::CustomerReplyClassified, $automation->fresh()->trigger_type);
        $this->assertTrue($automation->isActive());
    }

    public function test_new_automations_default_to_draft(): void
    {
        $automation = Automation::factory()->create();

        $this->assertSame(AutomationStatus::Draft, $automation->fresh()->status);
        $this->assertFalse($automation->isActive());
    }

    public function test_conditions_belong_to_the_automation_in_order(): void
    {
        $automation = $this->automation();
        $automation->conditions()->create(['type' => AutomationConditionType::ConfidenceGreaterThan, 'operator' => AutomationConditionOperator::GreaterThanOrEqual, 'value' => '0.8', 'sort_order' => 2]);
        $automation->conditions()->create(['type' => AutomationConditionType::IntentEquals, 'operator' => AutomationConditionOperator::Equals, 'value' => 'ready_to_book', 'sort_order' => 1]);

        $conditions = $automation->fresh()->conditions;

        $this->assertSame([AutomationConditionType::IntentEquals, AutomationConditionType::ConfidenceGreaterThan], $conditions->pluck('type')->all());
        $this->assertSame(AutomationConditionOperator::Equals, $conditions[0]->operator);
        $this->assertSame('ready_to_book', $conditions[0]->value);
        $this->assertTrue($conditions[0]->automation->is($automation));
    }

    public function test_actions_belong_to_the_automation_with_configuration_and_approval_defaults(): void
    {
        $automation = $this->automation();
        $automation->actions()->create(['type' => AutomationActionType::CreateTask, 'configuration' => ['priority' => 'high'], 'sort_order' => 1]);
        $automation->actions()->create(['type' => AutomationActionType::SendEmail, 'sort_order' => 2]);
        $automation->actions()->create(['type' => AutomationActionType::NotifyUser, 'requires_approval' => true, 'sort_order' => 3]);

        $actions = $automation->fresh()->actions;

        $this->assertSame(['create_task', 'send_email', 'notify_user'], $actions->pluck('type')->map->value->all());
        $this->assertSame(['priority' => 'high'], $actions[0]->configuration);
        $this->assertSame([], $actions[1]->configuration);
        $this->assertFalse($actions[0]->requires_approval);
        $this->assertTrue($actions[1]->requires_approval, 'send_email requires approval by default');
        $this->assertTrue($actions[2]->requires_approval, 'explicit values are kept');
        $this->assertTrue($actions[0]->automation->is($automation));
    }

    public function test_runs_belong_to_the_automation_and_organization(): void
    {
        $automation = $this->automation();
        $run = $this->createRun($automation);

        $run->refresh();
        $this->assertTrue($run->automation->is($automation));
        $this->assertSame($automation->organization_id, $run->organization->id);
        $this->assertSame(AutomationTriggerType::CustomerReplyClassified, $run->event_type);
        $this->assertSame(AutomationRunStatus::Running, $run->status);
        $this->assertTrue($automation->runs->contains($run));
        $this->assertTrue($automation->organization->automationRuns->contains($run));
    }

    public function test_action_runs_belong_to_runs(): void
    {
        $automation = $this->automation();
        $action = $automation->actions()->create(['type' => AutomationActionType::AddCustomerTag, 'configuration' => ['tag' => 'ready-to-book']]);
        $run = $this->createRun($automation);

        $actionRun = new AutomationActionRun;
        $actionRun->forceFill([
            'automation_run_id' => $run->id,
            'automation_action_id' => $action->id,
            'action_type' => AutomationActionType::AddCustomerTag,
            'status' => AutomationActionRunStatus::Completed,
            'result' => ['tag' => 'ready-to-book', 'created' => true],
            'executed_at' => now(),
        ])->save();

        $actionRun->refresh();
        $this->assertTrue($actionRun->run->is($run));
        $this->assertTrue($actionRun->action->is($action));
        $this->assertSame(AutomationActionRunStatus::Completed, $actionRun->status);
        $this->assertEquals(['tag' => 'ready-to-book', 'created' => true], $actionRun->result);
        $this->assertTrue($run->actionRuns->contains($actionRun));

        // History survives the action being removed later.
        $action->delete();
        $this->assertNull($actionRun->fresh()->automation_action_id);
    }

    // Database guarantees

    public function test_the_same_event_cannot_create_two_runs_of_an_automation(): void
    {
        $automation = $this->automation();
        $this->createRun($automation, 'classification:7');
        $this->createRun($automation, 'classification:8');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => $this->createRun($automation, 'classification:7'));
    }

    public function test_an_action_runs_at_most_once_per_run(): void
    {
        $automation = $this->automation();
        $action = $automation->actions()->create(['type' => AutomationActionType::CreateTask]);
        $run = $this->createRun($automation);
        $make = fn () => (new AutomationActionRun)->forceFill(['automation_run_id' => $run->id, 'automation_action_id' => $action->id, 'action_type' => 'create_task'])->save();
        $make();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction($make);
    }

    public function test_database_rejects_a_run_whose_organization_differs_from_its_automation(): void
    {
        $automation = $this->automation();
        $otherOrganization = Organization::factory()->create();

        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->createRun($automation, 'classification:1', $otherOrganization->id));
    }

    public function test_deleting_an_automation_or_organization_cascades(): void
    {
        $automation = $this->automation();
        $automation->conditions()->create(['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'interested']);
        $automation->actions()->create(['type' => 'create_task']);
        $this->createRun($automation);
        $other = $this->automation();
        $this->createRun($other);

        $automation->delete();
        $this->assertSame(0, DB::table('automation_conditions')->where('automation_id', $automation->id)->count());
        $this->assertSame(0, DB::table('automation_actions')->where('automation_id', $automation->id)->count());
        $this->assertSame(0, DB::table('automation_runs')->where('automation_id', $automation->id)->count());

        $other->organization->delete();
        $this->assertSame(0, DB::table('automations')->count());
        $this->assertSame(0, DB::table('automation_runs')->count());
    }

    // Organization isolation

    public function test_queries_are_scoped_to_the_organization(): void
    {
        $mine = $this->automation();
        $theirs = $this->automation();

        $this->assertSame([$mine->id], Automation::query()->forOrganization($mine->organization)->pluck('id')->all());
        $this->assertSame([$mine->id], $mine->organization->automations()->pluck('id')->all());
        $this->assertNull($mine->organization->automations()->find($theirs->id));
        $this->assertSame([$mine->id], Automation::query()->forOrganization($mine->organization_id)->activeFor(AutomationTriggerType::CustomerReplyClassified)->pluck('id')->all());
    }

    public function test_tenant_and_audit_fields_are_not_mass_assignable(): void
    {
        $automation = new Automation(['organization_id' => 99, 'created_by' => 5, 'updated_by' => 5, 'name' => 'x']);

        $this->assertNull($automation->organization_id);
        $this->assertNull($automation->created_by);
        $this->assertNull($automation->updated_by);
    }

    public function test_policy_limits_automations_to_admins_of_the_same_organization(): void
    {
        $automation = $this->automation();
        $admin = User::factory()->admin()->for($automation->organization)->create();
        $member = User::factory()->for($automation->organization)->create();
        $outsider = User::factory()->admin()->create();

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($admin->can($ability, $automation), $ability);
            $this->assertFalse($member->can($ability, $automation), $ability);
            $this->assertFalse($outsider->can($ability, $automation), $ability);
        }

        $this->assertTrue($admin->can('create', Automation::class));
        $this->assertFalse($member->can('create', Automation::class));
    }
}

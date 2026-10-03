<?php

namespace Tests\Feature\Automation;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\Automations\AutomationEditor;
use App\Livewire\Settings\Automations\AutomationIndex;
use App\Livewire\Settings\Automations\AutomationRunLog;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = User::factory()->admin()->create();
    }

    private function automation(array $attributes = [], ?Organization $organization = null): Automation
    {
        return Automation::factory()->create(['organization_id' => ($organization ?? $this->admin->organization)->id] + $attributes);
    }

    private function editor(?int $automationId = null)
    {
        return Livewire::actingAs($this->admin)->test(AutomationEditor::class, ['automationId' => $automationId]);
    }

    // Access

    public function test_admins_can_open_the_automation_pages(): void
    {
        $automation = $this->automation();

        $this->actingAs($this->admin)->get('/settings/automations')->assertOk()->assertSee('Automations')->assertSee('Templates');
        $this->actingAs($this->admin)->get('/settings/automations/create')->assertOk()->assertSee('When');
        $this->actingAs($this->admin)->get("/settings/automations/{$automation->id}/edit")->assertOk();
        $this->actingAs($this->admin)->get("/settings/automations/{$automation->id}/runs")->assertOk();
    }

    public function test_members_and_guests_cannot_manage_automations(): void
    {
        $member = User::factory()->for($this->admin->organization)->create();
        $automation = $this->automation();

        $this->get('/settings/automations')->assertRedirect();
        $this->actingAs($member)->get('/settings/automations')->assertForbidden();
        $this->actingAs($member)->get("/settings/automations/{$automation->id}/edit")->assertForbidden();
        $this->actingAs($member)->get("/settings/automations/{$automation->id}/runs")->assertForbidden();
        $this->actingAs($member)->get('/inbox')->assertDontSee('href="'.route('settings.automations.index').'"', false);
    }

    public function test_another_organizations_automation_is_not_found(): void
    {
        $foreign = $this->automation(organization: Organization::factory()->create());

        $this->actingAs($this->admin)->get("/settings/automations/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->get("/settings/automations/{$foreign->id}/runs")->assertNotFound();

        Livewire::actingAs($this->admin)->test(AutomationIndex::class)
            ->call('pause', $foreign->id)->assertNotFound();
        Livewire::actingAs($this->admin)->test(AutomationIndex::class)
            ->call('confirmDelete', $foreign->id)->assertNotFound();

        $this->assertModelExists($foreign);
    }

    // List

    public function test_the_list_shows_only_this_organizations_automations_with_details(): void
    {
        $mine = $this->automation(['name' => 'Ready to book flow', 'status' => 'active', 'trigger_type' => 'customer_reply_classified']);
        $this->automation(['name' => 'Foreign flow'], Organization::factory()->create());
        AutomationRun::query()->forceCreate(['organization_id' => $mine->organization_id, 'automation_id' => $mine->id, 'event_type' => 'customer_reply_classified', 'event_id' => 'classification:1', 'status' => 'completed']);

        Livewire::actingAs($this->admin)->test(AutomationIndex::class)
            ->assertSee('Ready to book flow')
            ->assertSee('Customer reply classified')
            ->assertSee('Active')
            ->assertSee('ago')
            ->assertSee($mine->created_at->format('M j, Y'))
            ->assertDontSee('Foreign flow');
    }

    public function test_pause_activate_and_delete(): void
    {
        $automation = $this->automation(['status' => 'active']);
        $automation->actions()->create(['type' => 'create_task', 'configuration' => ['title' => 'Call']]);

        $page = Livewire::actingAs($this->admin)->test(AutomationIndex::class);

        $page->call('pause', $automation->id);
        $this->assertSame(AutomationStatus::Paused, $automation->refresh()->status);

        $page->call('activate', $automation->id);
        $this->assertSame(AutomationStatus::Active, $automation->refresh()->status);

        $page->call('confirmDelete', $automation->id)->assertSee('Confirm delete')->call('delete');
        $this->assertModelMissing($automation);
    }

    public function test_settings_toggles_change_only_the_allowed_organization_settings(): void
    {
        $organization = $this->admin->organization;

        Livewire::actingAs($this->admin)->test(AutomationIndex::class)
            ->call('toggleSetting', 'automatic_email_enabled')
            ->call('toggleSetting', 'require_approval_for_email')
            ->call('toggleSetting', 'automations_enabled')
            ->call('toggleSetting', 'name')->assertStatus(422);

        $organization->refresh();
        $this->assertTrue($organization->automatic_email_enabled);
        $this->assertFalse($organization->require_approval_for_email);
        $this->assertFalse($organization->automations_enabled);
    }

    // Builder

    public function test_an_admin_builds_and_activates_an_automation(): void
    {
        $this->editor()
            ->set('name', 'Hot leads')
            ->set('triggerType', 'customer_reply_classified')
            ->call('addCondition')
            ->set('conditions.0.type', 'intent_equals')
            ->set('conditions.0.value', 'ready_to_book')
            ->call('addCondition')
            ->set('conditions.1.type', 'confidence_greater_than')
            ->assertSet('conditions.1.operator', 'greater_than')
            ->set('conditions.1.operator', 'greater_than_or_equal')
            ->set('conditions.1.value', '80')
            ->call('addAction')
            ->set('actions.0.configuration.title', 'Call {customer_name}')
            ->set('actions.0.configuration.priority', 'high')
            ->call('addAction')
            ->set('actions.1.type', 'add_customer_tag')
            ->set('actions.1.configuration.tag', 'Hot lead')
            ->call('saveAndActivate')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.automations.index'));

        $automation = Automation::sole()->load(['conditions', 'actions']);
        $this->assertSame($this->admin->organization_id, $automation->organization_id);
        $this->assertSame($this->admin->id, $automation->created_by);
        $this->assertSame(AutomationStatus::Active, $automation->status);
        $this->assertSame([['intent_equals', 'equals', 'ready_to_book'], ['confidence_greater_than', 'greater_than_or_equal', '0.8']],
            $automation->conditions->map(fn ($c) => [$c->type->value, $c->operator->value, $c->value])->all());
        $this->assertSame(['title' => 'Call {customer_name}', 'priority' => 'high'], $automation->actions[0]->configuration);
        $this->assertSame(['tag' => 'Hot lead'], $automation->actions[1]->configuration);
    }

    public function test_editing_loads_and_updates_the_automation(): void
    {
        $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, 'ready_to_book');

        $this->editor($automation->id)
            ->assertSet('name', 'Customer Ready to Book')
            ->assertSet('conditions.1.value', '80')
            ->set('name', 'Ready to book (edited)')
            ->call('removeAction', 2)
            ->call('save')
            ->assertHasNoErrors();

        $automation->refresh()->load('actions');
        $this->assertSame('Ready to book (edited)', $automation->name);
        $this->assertSame(['create_task', 'add_customer_tag'], $automation->actions->map(fn ($a) => $a->type->value)->all());
        $this->assertSame('0.8', $automation->conditions()->reorder('sort_order', 'desc')->value('value'));
    }

    public function test_activation_requires_a_complete_valid_automation(): void
    {
        $this->editor()
            ->set('name', '')
            ->call('addCondition')
            ->set('conditions.0.type', 'confidence_greater_than')
            ->set('conditions.0.value', '180')
            ->call('addAction')
            ->set('actions.0.type', 'add_customer_tag')
            ->call('saveAndActivate')
            ->assertHasErrors(['name', 'conditions.0', 'actions.0']);

        $this->assertSame(0, Automation::count());

        $draft = $this->automation(['status' => 'draft']);
        Livewire::actingAs($this->admin)->test(AutomationIndex::class)
            ->call('activate', $draft->id)
            ->assertSee('can’t be activated yet: Add at least one action before activating.');
        $this->assertSame(AutomationStatus::Draft, $draft->refresh()->status);
    }

    public function test_the_builder_only_accepts_implemented_triggers_conditions_and_actions(): void
    {
        $builder = app(AutomationBuilder::class);
        $base = ['name' => 'X', 'trigger_type' => 'customer_reply_classified', 'conditions' => [], 'actions' => [['type' => 'create_task', 'configuration' => ['title' => 'A']]]];

        $invalid = [
            'trigger_type' => ['trigger_type' => 'estimate_sent'],
            'conditions.0' => ['conditions' => [['type' => 'customer_status_equals', 'operator' => 'equals', 'value' => 'lead']]],
            'actions.0' => ['actions' => [['type' => 'delete_customer']]],
        ];

        foreach ($invalid as $field => $override) {
            try {
                $builder->validated($override + $base, requireActions: true);
                $this->fail("{$field} should be rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        // Unknown configuration keys are dropped.
        $clean = $builder->validated(['actions' => [['type' => 'create_task', 'configuration' => ['title' => 'A', 'php' => 'system("ls")']]]] + $base, requireActions: true);
        $this->assertSame(['title' => 'A', 'priority' => 'medium'], $clean['actions'][0]['configuration']);

        $this->editor()->assertDontSee('Customer status')->assertDontSee('Estimate status')->assertDontSee('Estimate sent');
    }

    public function test_organization_id_from_the_browser_is_ignored(): void
    {
        $other = Organization::factory()->create();

        $this->editor()
            ->set('name', 'Mine')
            ->call('addAction')
            ->set('actions.0.configuration.title', 'A')
            ->set('actions.0.configuration.organization_id', $other->id)
            ->call('save');

        $automation = Automation::sole();
        $this->assertSame($this->admin->organization_id, $automation->organization_id);
        $this->assertArrayNotHasKey('organization_id', $automation->actions()->sole()->configuration);
        $this->assertSame(0, $other->automations()->count());
    }

    public function test_email_actions_reject_unsupported_variables(): void
    {
        $this->editor()
            ->set('name', 'Email')
            ->call('addAction')
            ->set('actions.0.type', 'send_email')
            ->assertSet('actions.0.requires_approval', true)
            ->set('actions.0.configuration.subject', 'Hi {{customer.first_name}}')
            ->set('actions.0.configuration.body', 'Run {{php_code}}')
            ->call('save')
            ->assertHasErrors('actions.0')
            ->assertSee('Unsupported variable {{php_code}}.')
            ->set('actions.0.configuration.body', 'Your estimate {{estimate.number}}')
            ->call('save')
            ->assertSee('{{estimate.number}} cannot be used yet');

        $this->assertSame(0, Automation::count());
    }

    // Templates

    public function test_templates_are_copied_into_the_organization_as_drafts(): void
    {
        $page = Livewire::actingAs($this->admin)->test(AutomationIndex::class);

        foreach (array_keys(AutomationTemplates::all()) as $key) {
            $page->call('installTemplate', $key);
        }
        $page->call('installTemplate', 'give_discount')->assertNotFound();

        $automations = Automation::with(['conditions', 'actions'])->orderBy('id')->get();
        $this->assertSame(['Customer Ready to Book', 'Price Objection', 'Interested Customer', 'Customer Wants Callback'], $automations->pluck('name')->all());
        $this->assertTrue($automations->every(fn ($a) => $a->organization_id === $this->admin->organization_id && $a->status === AutomationStatus::Draft));

        [$ready, $price, $interested, $callback] = $automations;
        $summary = fn (Automation $a) => [
            $a->conditions->map(fn ($c) => "{$c->type->value} {$c->operator->value} {$c->value}")->all(),
            $a->actions->map(fn ($x) => $x->type->value)->all(),
        ];

        $this->assertSame([['intent_equals equals ready_to_book', 'confidence_greater_than greater_than_or_equal 0.8'], ['create_task', 'add_customer_tag', 'notify_user']], $summary($ready));
        $this->assertSame('high', $ready->actions[0]->configuration['priority']);
        $this->assertSame([['intent_equals equals price_objection'], ['create_task', 'add_customer_tag', 'notify_user']], $summary($price));
        $this->assertSame([['intent_equals equals interested', 'confidence_greater_than greater_than_or_equal 0.8'], ['schedule_follow_up', 'add_customer_tag']], $summary($interested));
        $this->assertSame([['intent_equals equals wants_callback'], ['create_task', 'notify_user', 'update_conversation_status']], $summary($callback));
        $this->assertSame('waiting_business', $callback->actions[2]->configuration['status']);

        // Each template is valid for activation as installed.
        foreach ($automations as $automation) {
            app(AutomationBuilder::class)->activate($automation, $this->admin);
        }
        $this->assertSame(4, Automation::where('status', 'active')->count());
    }

    // Run log and timeline

    private function runWithActions(Automation $automation, ?Conversation $conversation = null): AutomationRun
    {
        $run = AutomationRun::query()->forceCreate([
            'organization_id' => $automation->organization_id, 'automation_id' => $automation->id, 'conversation_id' => $conversation?->id,
            'event_type' => 'customer_reply_classified', 'event_id' => 'classification:77', 'status' => AutomationRunStatus::Completed,
            'context' => ['intent' => 'ready_to_book', 'confidence' => 0.92], 'started_at' => now(),
        ]);
        AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'create_task', 'status' => AutomationActionRunStatus::Completed, 'result' => ['message' => 'Task created: Book John Smith'], 'executed_at' => now()]);
        AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'send_email', 'status' => AutomationActionRunStatus::Skipped, 'result' => ['message' => 'Automatic emails are turned off for this organization.'], 'executed_at' => now()]);

        return $run;
    }

    public function test_the_run_log_lists_runs_and_shows_each_action_result(): void
    {
        $automation = $this->automation(['name' => 'Ready flow']);
        $run = $this->runWithActions($automation);

        Livewire::actingAs($this->admin)->test(AutomationRunLog::class, ['automationId' => $automation->id])
            ->assertSee('#'.$run->id)->assertSee('classification:77')->assertDontSee('Task created');

        Livewire::actingAs($this->admin)->test(AutomationRunLog::class, ['automationId' => $automation->id, 'runId' => $run->id])
            ->assertSeeInOrder(['Run #'.$run->id, 'Completed', 'Ready to book', '92%', 'Create task', 'Task created: Book John Smith', 'Send email', 'Automatic emails are turned off for this organization.', 'Skipped']);

        // A run of another automation can't be opened through this one.
        $other = $this->runWithActions($this->automation());
        $this->actingAs($this->admin)->get("/settings/automations/{$automation->id}/runs/{$other->id}")->assertNotFound();
    }

    public function test_automation_activity_appears_in_the_conversation_timeline(): void
    {
        $customer = Customer::factory()->for($this->admin->organization)->create(['name' => 'John Smith']);
        $conversation = Conversation::factory()->for($customer)->create(['organization_id' => $this->admin->organization_id]);
        $this->runWithActions($this->automation(['name' => 'Customer Ready to Book']), $conversation);

        Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $conversation->id])
            ->assertSeeInOrder(['Automation activity', 'Automation “Customer Ready to Book”', 'Completed', 'Create task: Task created: Book John Smith', 'Send email: Automatic emails are turned off']);
    }
}

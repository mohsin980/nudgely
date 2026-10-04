<?php

namespace App\Livewire\Automations;

use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationStatus;
use App\Models\Automation;
use App\Models\User;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationSummary;
use App\Services\Automation\AutomationTester;
use App\Services\Automation\Registry\ActionRegistry;
use App\Services\Automation\Registry\ConditionFieldDefinition;
use App\Services\Automation\Registry\ConditionFieldRegistry;
use App\Services\Automation\Registry\TriggerDefinition;
use App\Services\Automation\Registry\TriggerRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The step-by-step builder: Name → When → Wait & If → Then → Review (test, save, activate).
 *
 * Only form state lives here. Every choice offered comes from the registries for the chosen
 * trigger, and AutomationBuilder validates and saves (in one transaction).
 */
#[Layout('components.layouts.app')]
class AutomationForm extends Component
{
    use ResolvesAutomations;

    public const STEPS = [1 => 'Name', 2 => 'When', 3 => 'Wait & If', 4 => 'Then', 5 => 'Review'];

    public const WAITS = ['' => 'No wait — right away', '60' => '1 hour', '240' => '4 hours', '1440' => '1 day', '2880' => '2 days', '4320' => '3 days', '7200' => '5 days', '10080' => '7 days', '20160' => '14 days'];

    /** Which builder errors belong to which step. */
    private const STEP_FIELDS = [1 => ['name', 'description'], 2 => ['trigger_type'], 3 => ['wait_minutes', 'condition_match', 'conditions'], 4 => ['actions']];

    #[Locked]
    public ?int $automationId = null;

    public int $step = 1;

    #[Locked]
    public int $furthestStep = 1;

    public string $name = '';

    public string $description = '';

    public string $triggerType = '';

    public string $wait = '';

    public string $conditionMatch = 'all';

    /** @var list<array{type: string, operator: string, value: string}> */
    public array $conditions = [];

    /** @var list<array{type: string, configuration: array<string, mixed>, requires_approval: bool}> */
    public array $actions = [];

    public string $testSample = '';

    /** @var array<string, mixed>|null */
    public ?array $testReport = null;

    public ?string $notice = null;

    public function mount(?int $automationId = null): void
    {
        if ($automationId === null) {
            $this->authorize('create', Automation::class);

            return;
        }

        $automation = $this->findAutomation($automationId);
        $this->authorize('update', $automation);

        if ($automation->status === AutomationStatus::Archived) {
            session()->flash('automation-status', 'Restore this automation before editing it.');
            $this->redirectRoute('automations.show', $automation->id, navigate: true);

            return;
        }

        $input = app(AutomationBuilder::class)->toInput($automation->load(['conditions', 'actions']));

        $this->automationId = $automation->id;
        $this->name = $input['name'];
        $this->description = (string) $input['description'];
        $this->triggerType = $input['trigger_type'];
        $this->wait = (string) ($input['wait_minutes'] ?? '');
        $this->conditionMatch = $input['condition_match'];
        $this->conditions = array_map(fn (array $c) => ['type' => $c['type'], 'operator' => $c['operator'], 'value' => $this->displayValue($c['type'], $c['value'])], $input['conditions']);
        $this->actions = array_map(fn (array $a) => [
            'type' => $a['type'],
            'configuration' => array_map(fn ($v) => is_bool($v) ? $v : (string) $v, $a['configuration']),
            'requires_approval' => (bool) $a['requires_approval'],
        ], $input['actions']);
        $this->step = $this->furthestStep = 5;
    }

    // Steps

    public function next(AutomationBuilder $builder): void
    {
        if ($this->checkStep($builder, $this->step)) {
            $this->step = min(5, $this->step + 1);
            $this->furthestStep = max($this->furthestStep, $this->step);
        }
    }

    public function back(): void
    {
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function goToStep(int $step, AutomationBuilder $builder): void
    {
        abort_unless(isset(self::STEPS[$step]), 404);

        // Forward only through steps already reached, and only when the steps before are valid.
        if ($step > $this->furthestStep) {
            return;
        }

        // checkStep() validates every step before it and moves to the first one needing a fix.
        if ($step > 1 && ! $this->checkStep($builder, $step - 1)) {
            return;
        }

        $this->resetErrorBag();
        $this->step = $step;
    }

    // Conditions and actions

    public function addCondition(): void
    {
        $field = $this->trigger()?->conditionFields()[0] ?? null;

        if ($field !== null && count($this->conditions) < AutomationBuilder::MAX_CONDITIONS) {
            $this->conditions[] = ['type' => $field->key(), 'operator' => $field->operators()[0]->value, 'value' => ''];
        }
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
        $this->resetErrorBag();
    }

    public function addAction(): void
    {
        $definition = ActionRegistry::for($this->trigger())[0] ?? null;

        if ($definition !== null && count($this->actions) < (int) config('automation.limits.max_actions_per_run')) {
            $this->actions[] = ['type' => $definition->key(), 'configuration' => $this->stringDefaults($definition->defaults()), 'requires_approval' => false];
        }
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
        $this->resetErrorBag();
    }

    /**
     * Actions run in this order (sort_order is saved).
     */
    public function moveAction(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (isset($this->actions[$index], $this->actions[$target])) {
            [$this->actions[$index], $this->actions[$target]] = [$this->actions[$target], $this->actions[$index]];
            $this->resetErrorBag();
        }
    }

    public function updated(string $property, mixed $value): void
    {
        $this->testReport = null;

        if ($property === 'triggerType') {
            $trigger = $this->trigger();
            $before = count($this->conditions);
            $this->conditions = array_values(array_filter($this->conditions, fn (array $c) => $trigger !== null
                && ($field = ConditionFieldRegistry::all()[$c['type']] ?? null) !== null && $trigger->providesAll($field->requires)));
            $this->testSample = '';
            $this->notice = $before > count($this->conditions) ? 'Conditions that don’t apply to this trigger were removed.' : null;
        }

        if (preg_match('/^conditions\.(\d+)\.type$/', $property, $m) && isset($this->conditions[$m[1]])) {
            $field = ConditionFieldRegistry::all()[(string) $value] ?? null;
            $this->conditions[$m[1]]['operator'] = $field?->operators()[0]->value ?? 'equals';
            $this->conditions[$m[1]]['value'] = '';
        }

        if (preg_match('/^actions\.(\d+)\.type$/', $property, $m) && isset($this->actions[$m[1]])) {
            $this->actions[$m[1]]['configuration'] = $this->stringDefaults(ActionRegistry::find((string) $value)?->defaults() ?? []);
            $this->actions[$m[1]]['requires_approval'] = false;
        }
    }

    // Save, activate, test

    public function saveDraft(AutomationBuilder $builder): void
    {
        $automation = $this->persist($builder);

        if ($automation !== null) {
            session()->flash('automation-status', "“{$automation->name}” was saved".($automation->isActive() ? '.' : ' as a draft. It won’t run until you activate it.'));
            $this->redirectRoute('automations.show', $automation->id, navigate: true);
        }
    }

    public function activate(AutomationBuilder $builder): void
    {
        $problems = $builder->problems($this->currentOrganization(), $this->input());

        if ($problems !== []) {
            $this->step = 5;
            $this->addMappedErrors($problems);

            return;
        }

        $automation = $this->persist($builder);

        if ($automation === null) {
            return;
        }

        try {
            $builder->activate($automation, Auth::user());
        } catch (ValidationException $e) {
            $this->addMappedErrors(array_map(fn (array $m) => $m[0], $e->errors()));

            return;
        }

        session()->flash('automation-status', "“{$automation->name}” is active.");
        $this->redirectRoute('automations.show', $automation->id, navigate: true);
    }

    /**
     * Dry run against a real record: nothing is sent, created or changed.
     */
    public function runTest(AutomationBuilder $builder, AutomationTester $tester): void
    {
        $this->authorize($this->automationId ? 'update' : 'create', $this->automationId ? $this->findAutomation($this->automationId) : Automation::class);
        $this->resetErrorBag();
        $this->testReport = null;

        try {
            $data = $builder->validated($this->input(), requireActions: false, organization: $this->currentOrganization());
            $this->testReport = $tester->run($this->currentOrganization(), $data, $this->testSample);
        } catch (ValidationException $e) {
            $this->addMappedErrors(array_map(fn (array $m) => $m[0], $e->errors()));
        } catch (\InvalidArgumentException $e) {
            $this->addError('testSample', $e->getMessage());
        }
    }

    public function render(AutomationBuilder $builder, AutomationTester $tester)
    {
        $trigger = $this->trigger();
        $organization = $this->currentOrganization();

        return view('livewire.automations.automation-form', [
            'steps' => self::STEPS,
            'waits' => self::WAITS,
            'triggers' => TriggerRegistry::available(),
            'trigger' => $trigger,
            'conditionFields' => collect($trigger?->conditionFields() ?? [])->groupBy('group'),
            'fields' => ConditionFieldRegistry::all(),
            'actionDefinitions' => ActionRegistry::for($trigger),
            'variables' => $trigger?->variables() ?? [],
            'users' => User::query()->where('organization_id', $organization->id)->orderBy('name')->pluck('name', 'id'),
            'summary' => AutomationSummary::fromInput($this->input()),
            'problems' => $this->step === 5 ? $builder->problems($organization, $this->input()) : [],
            'samples' => $this->step === 5 && $trigger !== null ? $tester->samples($organization, $trigger) : [],
        ])->title($this->automationId ? 'Edit automation' : 'New automation');
    }

    private function trigger(): ?TriggerDefinition
    {
        $trigger = TriggerRegistry::find($this->triggerType ?: null);

        return $trigger?->available ? $trigger : null;
    }

    /**
     * Validate the steps up to this one; show this step's problems.
     */
    private function checkStep(AutomationBuilder $builder, int $step): bool
    {
        $this->resetErrorBag();

        try {
            $builder->validated($this->input(), requireActions: $step === 4, organization: $this->currentOrganization());

            return true;
        } catch (ValidationException $e) {
            $errors = array_filter(
                array_map(fn (array $m) => $m[0], $e->errors()),
                fn (string $key) => collect(range(1, $step))->flatMap(fn (int $s) => self::STEP_FIELDS[$s] ?? [])
                    ->contains(fn (string $prefix) => $key === $prefix || str_starts_with($key, $prefix.'.')),
                ARRAY_FILTER_USE_KEY,
            );

            $this->addMappedErrors($errors);

            if ($errors !== []) {
                // Send the user to the first step that needs fixing.
                $this->step = collect(self::STEP_FIELDS)->search(fn (array $prefixes) => collect(array_keys($errors))
                    ->contains(fn (string $key) => collect($prefixes)->contains(fn ($p) => $key === $p || str_starts_with($key, $p.'.')))) ?: $step;
            }

            return $errors === [];
        }
    }

    private function persist(AutomationBuilder $builder): ?Automation
    {
        $existing = $this->automationId === null ? null : $this->findAutomation($this->automationId);
        $this->authorize($existing ? 'update' : 'create', $existing ?? Automation::class);
        $this->resetErrorBag();

        try {
            $automation = $builder->save($this->currentOrganization(), Auth::user(), $this->input(), $existing);
        } catch (ValidationException $e) {
            $this->addMappedErrors(array_map(fn (array $m) => $m[0], $e->errors()));

            return null;
        }

        $this->automationId = $automation->id;

        return $automation;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->triggerType,
            'condition_match' => $this->conditionMatch,
            'wait_minutes' => $this->wait === '' ? null : $this->wait,
            'conditions' => array_map(fn (array $c) => ['type' => (string) ($c['type'] ?? ''), 'operator' => (string) ($c['operator'] ?? ''), 'value' => $this->storedValue((string) ($c['type'] ?? ''), (string) ($c['value'] ?? ''))], $this->conditions),
            'actions' => array_map(fn (array $a) => ['type' => (string) ($a['type'] ?? ''), 'configuration' => is_array($a['configuration'] ?? null) ? $a['configuration'] : [], 'requires_approval' => (bool) ($a['requires_approval'] ?? false)], $this->actions),
        ];
    }

    /**
     * @param  array<string, string>  $errors  Builder keys => message.
     */
    private function addMappedErrors(array $errors): void
    {
        $map = ['trigger_type' => 'triggerType', 'wait_minutes' => 'wait', 'condition_match' => 'conditionMatch'];

        foreach ($errors as $key => $message) {
            $this->addError($map[$key] ?? $key, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, string>
     */
    private function stringDefaults(array $defaults): array
    {
        return array_map(fn ($v) => $v === null ? '' : (string) $v, $defaults);
    }

    /**
     * Percentages are shown as 80 and stored as 0.8.
     */
    private function displayValue(string $type, string $value): string
    {
        return (ConditionFieldRegistry::all()[$type] ?? null)?->dataType === ConditionFieldDefinition::PERCENT && is_numeric($value)
            ? (string) round((float) $value * 100, 2)
            : $value;
    }

    private function storedValue(string $type, string $value): string
    {
        $value = trim($value);

        if ((ConditionFieldRegistry::all()[$type] ?? null)?->dataType !== ConditionFieldDefinition::PERCENT) {
            return $value;
        }

        // Anything that isn't a plain 0–100 percentage is passed through and rejected by the builder.
        return preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) && (float) $value <= 100
            ? rtrim(rtrim(number_format((float) $value / 100, 4, '.', ''), '0'), '.')
            : $value;
    }

    /**
     * For the view: does this condition's operator need a value?
     */
    public function needsValue(string $operator): bool
    {
        return AutomationConditionOperator::tryFrom($operator)?->needsValue() ?? true;
    }
}

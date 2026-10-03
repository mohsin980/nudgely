<?php

namespace App\Livewire\Settings\Automations;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\TaskPriority;
use App\Models\Automation;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\EmailTemplateRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The WHEN / IF / THEN form. It only holds form state: AutomationBuilder validates and saves.
 */
#[Layout('components.layouts.app')]
class AutomationEditor extends Component
{
    use ResolvesAutomations;

    #[Locked]
    public ?int $automationId = null;

    public string $name = '';

    public string $description = '';

    public string $triggerType = 'customer_reply_classified';

    /**
     * @var list<array{type: string, operator: string, value: string}>
     */
    public array $conditions = [];

    /**
     * @var list<array{type: string, configuration: array<string, mixed>, requires_approval: bool}>
     */
    public array $actions = [];

    public function mount(?int $automationId = null): void
    {
        if ($automationId === null) {
            $this->authorize('create', Automation::class);

            return;
        }

        $automation = $this->findAutomation($automationId);
        $this->authorize('update', $automation);

        $input = app(AutomationBuilder::class)->toInput($automation->load(['conditions', 'actions']));

        $this->automationId = $automation->id;
        $this->name = $input['name'];
        $this->description = (string) $input['description'];
        $this->triggerType = $input['trigger_type'];
        $this->conditions = array_map(fn (array $c) => ['type' => $c['type'], 'operator' => $c['operator'], 'value' => $this->displayValue($c['type'], $c['value'])], $input['conditions']);
        $this->actions = array_map(fn (array $a) => ['type' => $a['type'], 'configuration' => array_map(fn ($v) => (string) $v, $a['configuration']), 'requires_approval' => (bool) $a['requires_approval']], $input['actions']);
    }

    public function addCondition(): void
    {
        $this->conditions[] = ['type' => AutomationConditionType::IntentEquals->value, 'operator' => 'equals', 'value' => ''];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    public function addAction(): void
    {
        $this->actions[] = ['type' => AutomationActionType::CreateTask->value, 'configuration' => [], 'requires_approval' => false];
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
    }

    /**
     * A new condition or action type starts from that type's defaults.
     */
    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^conditions\.(\d+)\.type$/', $property, $m) && isset($this->conditions[$m[1]])) {
            $type = AutomationConditionType::tryFrom((string) $value);
            $this->conditions[$m[1]]['operator'] = $type?->defaultOperator()->value ?? 'equals';
            $this->conditions[$m[1]]['value'] = '';
        }

        if (preg_match('/^actions\.(\d+)\.type$/', $property, $m) && isset($this->actions[$m[1]])) {
            $this->actions[$m[1]]['configuration'] = [];
            $this->actions[$m[1]]['requires_approval'] = AutomationActionType::tryFrom((string) $value)?->requiresApprovalByDefault() ?? false;
        }
    }

    public function save(AutomationBuilder $builder): void
    {
        if ($this->persist($builder) !== null) {
            $this->redirectRoute('settings.automations.index', navigate: true);
        }
    }

    public function saveAndActivate(AutomationBuilder $builder): void
    {
        $automation = $this->persist($builder);

        if ($automation === null) {
            return;
        }

        try {
            $builder->activate($automation, Auth::user());
        } catch (ValidationException $e) {
            $this->addMappedErrors($e);

            return;
        }

        session()->flash('automation-status', "“{$automation->name}” is active.");
        $this->redirectRoute('settings.automations.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.settings.automations.automation-editor', [
            'triggers' => AutomationBuilder::triggers(),
            'conditionTypes' => AutomationBuilder::CONDITION_TYPES,
            'actionTypes' => AutomationActionType::cases(),
            'intents' => CustomerReplyIntent::cases(),
            'conversationStatuses' => ConversationStatus::cases(),
            'priorities' => TaskPriority::cases(),
            // Shown as {{customer.first_name}} etc.; built here so Blade never parses the braces.
            'variables' => collect(EmailTemplateRenderer::VARIABLES)->mapWithKeys(fn (string $label, string $name) => ['{{'.$name.'}}' => $label])->all(),
        ])->title($this->automationId ? 'Edit automation' : 'New automation');
    }

    private function persist(AutomationBuilder $builder): ?Automation
    {
        $existing = $this->automationId === null ? null : $this->findAutomation($this->automationId);
        $this->authorize($existing ? 'update' : 'create', $existing ?? Automation::class);
        $this->resetErrorBag();

        try {
            $automation = $builder->save($this->currentOrganization(), Auth::user(), [
                'name' => $this->name,
                'description' => $this->description,
                'trigger_type' => $this->triggerType,
                'conditions' => array_map(fn (array $c) => ['type' => $c['type'] ?? '', 'operator' => $c['operator'] ?? '', 'value' => $this->storedValue($c['type'] ?? '', (string) ($c['value'] ?? ''))], $this->conditions),
                'actions' => array_map(fn (array $a) => ['type' => $a['type'] ?? '', 'configuration' => $a['configuration'] ?? [], 'requires_approval' => (bool) ($a['requires_approval'] ?? false)], $this->actions),
            ], $existing);
        } catch (ValidationException $e) {
            $this->addMappedErrors($e);

            return null;
        }

        $this->automationId = $automation->id;
        session()->flash('automation-status', "“{$automation->name}” was saved.");

        return $automation;
    }

    private function addMappedErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $key => $messages) {
            $this->addError($key === 'trigger_type' ? 'triggerType' : $key, $messages[0]);
        }
    }

    /**
     * Confidence is shown as a percentage (80) and stored as a fraction (0.8).
     */
    private function displayValue(string $type, string $value): string
    {
        return $type === AutomationConditionType::ConfidenceGreaterThan->value && is_numeric($value)
            ? (string) round((float) $value * 100, 2)
            : $value;
    }

    private function storedValue(string $type, string $value): string
    {
        $value = trim($value);

        if ($type !== AutomationConditionType::ConfidenceGreaterThan->value) {
            return $value;
        }

        // Anything that isn't a plain 0–100 percentage is passed through and rejected by the builder.
        return preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) && (float) $value <= 100
            ? rtrim(rtrim(number_format((float) $value / 100, 4, '.', ''), '0'), '.')
            : $value;
    }
}

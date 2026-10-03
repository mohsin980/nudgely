<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\ConversationStatus;
use App\Enums\TaskPriority;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Automation;
use App\Models\CustomerTag;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automation\Actions\ScheduleFollowUpAction;
use App\Services\Automation\Actions\SendEmailAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Validates and saves automations built in the UI or installed from templates.
 *
 * Input is a plain array:
 *   name, description, trigger_type,
 *   conditions: [{type, operator, value}],
 *   actions: [{type, configuration: {...}, requires_approval?}]
 *
 * The organization and user always come from the caller's authenticated context, never
 * from the input. Only triggers, conditions and actions the engine implements are accepted,
 * and each action's configuration is reduced to its known keys.
 */
class AutomationBuilder
{
    public const MAX_CONDITIONS = 10;

    /**
     * Condition types the builder offers; customer and estimate status have no data yet.
     */
    public const CONDITION_TYPES = [
        AutomationConditionType::IntentEquals,
        AutomationConditionType::ConfidenceGreaterThan,
        AutomationConditionType::ConversationStatusEquals,
        AutomationConditionType::DaysSinceLastMessage,
    ];

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly EmailTemplateRenderer $templates,
    ) {}

    /**
     * @return list<AutomationTriggerType>
     */
    public static function triggers(): array
    {
        return array_values(array_filter(AutomationTriggerType::cases(), fn (AutomationTriggerType $trigger) => $trigger->isAvailable()));
    }

    /**
     * Create or update an automation. An active automation must stay valid for activation.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(Organization $organization, User $user, array $input, ?Automation $automation = null): Automation
    {
        abort_if($automation !== null && $automation->organization_id !== $organization->id, 404);

        $data = $this->validated($input, requireActions: $automation?->isActive() ?? false);

        return DB::transaction(function () use ($organization, $user, $data, $automation) {
            $automation ??= tap(new Automation, fn (Automation $new) => $new->forceFill([
                'organization_id' => $organization->id,
                'created_by' => $user->id,
                'status' => AutomationStatus::Draft,
            ]));

            $automation->fill([
                'name' => $data['name'],
                'description' => $data['description'],
                'trigger_type' => $data['trigger_type'],
            ]);
            $automation->forceFill(['updated_by' => $user->id])->save();

            // Replace the rule's steps; past action runs keep their history (action ID becomes null).
            $automation->conditions()->delete();
            $automation->actions()->delete();

            foreach ($data['conditions'] as $i => $condition) {
                $automation->conditions()->create($condition + ['sort_order' => $i]);
            }

            foreach ($data['actions'] as $i => $action) {
                $automation->actions()->create($action + ['sort_order' => $i]);
            }

            Log::info('Automation saved.', ['organization_id' => $organization->id, 'automation_id' => $automation->id, 'user_id' => $user->id]);

            return $automation->load(['conditions', 'actions']);
        });
    }

    /**
     * @throws ValidationException when the automation is incomplete or invalid
     */
    public function activate(Automation $automation, User $user): void
    {
        $this->validated($this->toInput($automation), requireActions: true);

        $automation->forceFill(['status' => AutomationStatus::Active, 'updated_by' => $user->id])->save();
    }

    public function pause(Automation $automation, User $user): void
    {
        $automation->forceFill(['status' => AutomationStatus::Paused, 'updated_by' => $user->id])->save();
    }

    /**
     * The saved automation in builder input format.
     *
     * @return array<string, mixed>
     */
    public function toInput(Automation $automation): array
    {
        return [
            'name' => $automation->name,
            'description' => $automation->description,
            'trigger_type' => $automation->getAttributes()['trigger_type'],
            'conditions' => $automation->conditions->map(fn ($c) => [
                'type' => $c->getAttributes()['type'],
                'operator' => $c->getAttributes()['operator'],
                'value' => $c->getAttributes()['value'],
            ])->all(),
            'actions' => $automation->actions->map(fn ($a) => [
                'type' => $a->getAttributes()['type'],
                'configuration' => $a->configuration ?? [],
                'requires_approval' => $a->requires_approval,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, description: ?string, trigger_type: string, conditions: list<array<string, string>>, actions: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validated(array $input, bool $requireActions): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter a name of up to 100 characters.';
        }

        $description = trim((string) ($input['description'] ?? '')) ?: null;
        if ($description !== null && mb_strlen($description) > 500) {
            $errors['description'] = 'The description may be up to 500 characters.';
        }

        $trigger = AutomationTriggerType::tryFrom((string) ($input['trigger_type'] ?? ''));
        if ($trigger === null || ! $trigger->isAvailable()) {
            $errors['trigger_type'] = 'Choose when this automation runs.';
        }

        $conditions = [];
        $conditionInput = is_array($input['conditions'] ?? null) ? array_values($input['conditions']) : [];

        if (count($conditionInput) > self::MAX_CONDITIONS) {
            $errors['conditions'] = 'An automation can have up to '.self::MAX_CONDITIONS.' conditions.';
        }

        foreach ($conditionInput as $i => $condition) {
            try {
                $conditions[] = $this->condition(is_array($condition) ? $condition : []);
            } catch (InvalidAutomationConditionException $e) {
                $errors["conditions.{$i}"] = $e->getMessage();
            }
        }

        $actions = [];
        $actionInput = is_array($input['actions'] ?? null) ? array_values($input['actions']) : [];
        $maxActions = (int) config('automation.limits.max_actions_per_run');

        if ($requireActions && $actionInput === []) {
            $errors['actions'] = 'Add at least one action before activating.';
        } elseif (count($actionInput) > $maxActions) {
            $errors['actions'] = "An automation can have up to {$maxActions} actions.";
        }

        foreach ($actionInput as $i => $action) {
            try {
                $actions[] = $this->action(is_array($action) ? $action : []);
            } catch (\InvalidArgumentException $e) {
                $errors["actions.{$i}"] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['name' => $name, 'description' => $description, 'trigger_type' => $trigger->value, 'conditions' => $conditions, 'actions' => $actions];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{type: string, operator: string, value: string}
     *
     * @throws InvalidAutomationConditionException
     */
    private function condition(array $condition): array
    {
        $type = AutomationConditionType::tryFrom((string) ($condition['type'] ?? ''));

        if ($type === null || ! in_array($type, self::CONDITION_TYPES, true)) {
            throw new InvalidAutomationConditionException('Choose a supported condition.');
        }

        $operator = (string) ($condition['operator'] ?? $type->defaultOperator()->value);
        $value = trim((string) ($condition['value'] ?? ''));

        $this->conditions->assertValid($type, $operator, $value);

        return ['type' => $type->value, 'operator' => $operator, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{type: AutomationActionType, configuration: array<string, mixed>, requires_approval: bool}
     *
     * @throws \InvalidArgumentException with a message safe to show
     */
    private function action(array $action): array
    {
        $type = AutomationActionType::tryFrom((string) ($action['type'] ?? ''))
            ?? throw new \InvalidArgumentException('Choose a supported action.');
        $config = is_array($action['configuration'] ?? null) ? $action['configuration'] : [];

        $configuration = match ($type) {
            AutomationActionType::CreateTask => [
                'title' => $this->text($config, 'title', 255, 'Enter a task title.'),
                'description' => $this->text($config, 'description', 2000, required: false),
                'priority' => $this->choice($config, 'priority', array_column(TaskPriority::cases(), 'value'), 'medium'),
                'due_in_hours' => $this->integer($config, 'due_in_hours', 0, 8760, required: false),
            ],
            AutomationActionType::AddCustomerTag => [
                'tag' => $this->tag($config),
            ],
            AutomationActionType::UpdateConversationStatus => [
                'status' => $this->choice($config, 'status', array_column(ConversationStatus::cases(), 'value')),
            ],
            AutomationActionType::NotifyUser => [
                'message' => $this->text($config, 'message', 500, 'Enter a notification message.'),
                'recipients' => $this->choice($config, 'recipients', ['admins', 'members'], 'admins'),
                'channel' => 'in_app',
            ],
            AutomationActionType::ScheduleFollowUp => [
                'delay_days' => $this->integer($config, 'delay_days', 1, ScheduleFollowUpAction::MAX_DELAY_DAYS),
                'subject' => $this->template($config, 'subject', SendEmailAction::MAX_SUBJECT),
                'body' => $this->template($config, 'body', SendEmailAction::MAX_BODY),
            ],
            AutomationActionType::SendEmail => [
                'subject' => $this->template($config, 'subject', SendEmailAction::MAX_SUBJECT),
                'body' => $this->template($config, 'body', SendEmailAction::MAX_BODY),
            ],
        };

        return [
            'type' => $type,
            'configuration' => array_filter($configuration, fn ($value) => $value !== null),
            // Emails require approval unless explicitly turned off.
            'requires_approval' => (bool) ($action['requires_approval'] ?? $type->requiresApprovalByDefault()),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function text(array $config, string $key, int $max, string $missing = '', bool $required = true): ?string
    {
        $value = is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';

        if ($value === '') {
            return $required ? throw new \InvalidArgumentException($missing) : null;
        }

        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $key))." may be up to {$max} characters.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $allowed
     */
    private function choice(array $config, string $key, array $allowed, ?string $default = null): string
    {
        $value = (string) ($config[$key] ?? $default ?? '');

        return in_array($value, $allowed, true) ? $value : throw new \InvalidArgumentException('Choose a valid '.str_replace('_', ' ', $key).'.');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function integer(array $config, string $key, int $min, int $max, bool $required = true): ?int
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return $required ? throw new \InvalidArgumentException('Enter '.str_replace('_', ' ', $key).'.') : null;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,5}$/', $value))) {
            throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $key))." must be a whole number from {$min} to {$max}.");
        }

        $value = (int) $value;

        return $value >= $min && $value <= $max ? $value : throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $key))." must be a whole number from {$min} to {$max}.");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function tag(array $config): string
    {
        $tag = $this->text($config, 'tag', 50, 'Enter a tag.');

        if (CustomerTag::slugFor($tag) === null) {
            throw new \InvalidArgumentException('The tag must contain letters or numbers.');
        }

        return $tag;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function template(array $config, string $key, int $max): string
    {
        $value = $this->text($config, $key, $max, 'Enter an email '.$key.'.');

        try {
            $this->templates->validate($value);
        } catch (InvalidEmailTemplateException $e) {
            throw new \InvalidArgumentException(ucfirst($key).': '.$e->getMessage());
        }

        return $value;
    }
}

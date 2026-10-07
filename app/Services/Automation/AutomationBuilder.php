<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\Billing\LimitKey;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\Automation;
use App\Models\AutomationHistory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automation\Registry\ActionDefinition;
use App\Services\Automation\Registry\ActionRegistry;
use App\Services\Automation\Registry\ActionValidation;
use App\Services\Automation\Registry\ConditionFieldRegistry;
use App\Services\Automation\Registry\Subject;
use App\Services\Automation\Registry\TriggerDefinition;
use App\Services\Automation\Registry\TriggerRegistry;
use App\Services\Billing\EntitlementService;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Validates and saves automations built in the UI or installed from templates, and changes
 * their status (activate, pause, archive, restore, duplicate).
 *
 * Input is a plain array:
 *   name, description, trigger_type, condition_match ("all" | "any"), wait_minutes,
 *   conditions: [{type, operator, value}],
 *   actions: [{type, configuration: {...}, requires_approval?}]
 *
 * Everything is checked against the registries for the chosen trigger: only conditions,
 * actions and {{variables}} the trigger can provide are accepted. The organization and user
 * always come from the caller's authenticated context, never from the input. A save writes
 * the automation, its conditions and its actions in one transaction.
 */
class AutomationBuilder
{
    public const MAX_CONDITIONS = 10;

    /** Longest WAIT: 60 days. */
    public const MAX_WAIT_MINUTES = 86_400;

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly EmailTemplateRenderer $templates,
        private readonly EmailService $email,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return list<AutomationTriggerType>
     */
    public static function triggers(): array
    {
        return array_map(fn (TriggerDefinition $d) => $d->type, TriggerRegistry::available());
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

        if ($automation?->status === AutomationStatus::Archived) {
            throw ValidationException::withMessages(['name' => 'Restore this automation before editing it.']);
        }

        $data = $this->validated($input, requireActions: $automation?->isActive() ?? false, organization: $organization);

        // A new automation takes one of the plan's slots (archived ones don't count).
        if ($automation === null) {
            $this->entitlements->assertAllows($organization, LimitKey::Automations);
        }

        if ($automation?->isActive() && ($sender = $this->senderError($organization, $data['actions'])) !== null) {
            throw ValidationException::withMessages(['actions' => $sender]);
        }

        return DB::transaction(function () use ($organization, $user, $data, $automation) {
            $created = $automation === null;
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
            $automation->forceFill([
                'condition_match' => $data['condition_match'],
                'wait_minutes' => $data['wait_minutes'],
                'updated_by' => $user->id,
            ])->save();

            // Replace the rule's steps; past action runs keep their history (action ID becomes null).
            $automation->conditions()->delete();
            $automation->actions()->delete();

            foreach ($data['conditions'] as $i => $condition) {
                $automation->conditions()->create($condition + ['sort_order' => $i]);
            }

            foreach ($data['actions'] as $i => $action) {
                $automation->actions()->create($action + ['sort_order' => $i]);
            }

            AutomationHistory::record($automation, $created ? 'created' : 'edited', $user);
            Log::info('Automation saved.', ['organization_id' => $organization->id, 'automation_id' => $automation->id, 'user_id' => $user->id]);

            return $automation->load(['conditions', 'actions']);
        });
    }

    /**
     * Everything that must be fixed before the automation can be activated (empty = ready).
     *
     * @return array<string, string>
     */
    public function activationErrors(Automation $automation): array
    {
        if ($automation->status === AutomationStatus::Archived) {
            return ['status' => 'Archived automations can’t be activated. Restore it as a draft first.'];
        }

        try {
            $data = $this->validated($this->toInput($automation->loadMissing(['conditions', 'actions'])), requireActions: true, organization: $automation->organization);
        } catch (ValidationException $e) {
            return array_map(fn (array $messages) => $messages[0], $e->errors());
        }

        $sender = $this->senderError($automation->organization, $data['actions']);

        return $sender === null ? [] : ['actions' => $sender];
    }

    /**
     * The same checks for unsaved builder input: what must be fixed before it can be activated.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function problems(Organization $organization, array $input): array
    {
        try {
            $data = $this->validated($input, requireActions: true, organization: $organization);
        } catch (ValidationException $e) {
            return array_map(fn (array $messages) => $messages[0], $e->errors());
        }

        $sender = $this->senderError($organization, $data['actions']);

        return $sender === null ? [] : ['actions' => $sender];
    }

    /**
     * @throws ValidationException when the automation is incomplete or invalid
     */
    public function activate(Automation $automation, User $user): void
    {
        $errors = $this->activationErrors($automation);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->changeStatus($automation, $user, AutomationStatus::Active, 'activated');
    }

    /**
     * New runs stop at once; queued actions re-check the status and are skipped.
     */
    public function pause(Automation $automation, User $user): void
    {
        if ($automation->isActive()) {
            $this->changeStatus($automation, $user, AutomationStatus::Paused, 'paused');
        }
    }

    /**
     * Archived: never runs, can't be activated directly, keeps its history and logs.
     */
    public function archive(Automation $automation, User $user): void
    {
        if ($automation->status !== AutomationStatus::Archived) {
            $this->changeStatus($automation, $user, AutomationStatus::Archived, 'archived', ['archived_at' => now()]);
        }
    }

    /**
     * Back from the archive as a draft (it must be activated again on purpose).
     */
    public function restore(Automation $automation, User $user): void
    {
        if ($automation->status === AutomationStatus::Archived) {
            $this->entitlements->assertAllows($automation->organization, LimitKey::Automations);
            $this->changeStatus($automation, $user, AutomationStatus::Draft, 'restored', ['archived_at' => null]);
        }
    }

    /**
     * A new draft with the same steps: new ID, no runs, no history but its own.
     */
    public function duplicate(Automation $automation, User $user): Automation
    {
        $this->entitlements->assertAllows($automation->organization, LimitKey::Automations);

        return DB::transaction(function () use ($automation, $user) {
            $copy = new Automation;
            $copy->fill([
                'name' => mb_substr('Copy of '.$automation->name, 0, 100),
                'description' => $automation->description,
                'trigger_type' => $automation->trigger_type,
            ]);
            $copy->forceFill([
                'organization_id' => $automation->organization_id,
                'status' => AutomationStatus::Draft,
                'condition_match' => $automation->condition_match,
                'wait_minutes' => $automation->wait_minutes,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();

            foreach ($automation->conditions as $condition) {
                $copy->conditions()->create($condition->only(['type', 'operator', 'value', 'sort_order']));
            }

            foreach ($automation->actions as $action) {
                $copy->actions()->create($action->only(['type', 'configuration', 'sort_order', 'requires_approval']));
            }

            AutomationHistory::record($copy, 'duplicated', $user, ['from' => $automation->name, 'from_id' => $automation->id]);

            return $copy->load(['conditions', 'actions']);
        });
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
            'condition_match' => $automation->condition_match ?? ConditionEvaluator::ALL,
            'wait_minutes' => $automation->wait_minutes,
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
     * @return array{name: string, description: ?string, trigger_type: string, condition_match: string, wait_minutes: ?int, conditions: list<array<string, string>>, actions: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validated(array $input, bool $requireActions, ?Organization $organization = null): array
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

        $trigger = TriggerRegistry::find(is_string($input['trigger_type'] ?? null) ? $input['trigger_type'] : null);
        if ($trigger === null || ! $trigger->available) {
            $errors['trigger_type'] = 'Choose when this automation runs.';
            $trigger = null;
        }

        $match = (string) ($input['condition_match'] ?? ConditionEvaluator::ALL);
        if (! in_array($match, [ConditionEvaluator::ALL, ConditionEvaluator::ANY], true)) {
            $errors['condition_match'] = 'Choose whether all or any conditions must match.';
        }

        $wait = $input['wait_minutes'] ?? null;
        if ($wait === '' || $wait === 0 || $wait === '0') {
            $wait = null;
        }
        if ($wait !== null && (! (is_int($wait) || (is_string($wait) && ctype_digit($wait))) || (int) $wait < 1 || (int) $wait > self::MAX_WAIT_MINUTES)) {
            $errors['wait_minutes'] = 'The wait must be between 1 minute and 60 days.';
        }

        $conditions = [];
        $conditionInput = is_array($input['conditions'] ?? null) ? array_values($input['conditions']) : [];

        if (count($conditionInput) > self::MAX_CONDITIONS) {
            $errors['conditions'] = 'An automation can have up to '.self::MAX_CONDITIONS.' conditions.';
        }

        foreach ($conditionInput as $i => $condition) {
            try {
                $conditions[] = $this->condition(is_array($condition) ? $condition : [], $trigger);
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

        $validation = new ActionValidation($trigger, $organization, $this->templates);

        foreach ($actionInput as $i => $action) {
            try {
                $actions[] = $this->action(is_array($action) ? $action : [], $trigger, $validation);
            } catch (\InvalidArgumentException $e) {
                $errors["actions.{$i}"] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'description' => $description,
            'trigger_type' => $trigger->key(),
            'condition_match' => $match,
            'wait_minutes' => $wait === null ? null : (int) $wait,
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }

    /**
     * Automations that email need a verified sender before they are switched on.
     *
     * @param  list<array<string, mixed>>  $actions
     */
    private function senderError(Organization $organization, array $actions): ?string
    {
        $sendsEmail = collect($actions)->contains(fn (array $a) => $a['type'] === AutomationActionType::SendEmail
            || ($a['type'] === AutomationActionType::ScheduleFollowUp && ($a['configuration']['kind'] ?? 'email') === 'email'));

        if (! $sendsEmail) {
            return null;
        }

        try {
            $this->email->assertCanSendFrom($organization->emailConnections()->default()->first(), $organization->id);
        } catch (EmailSendingNotAllowedException $e) {
            return 'This automation sends email: '.$e->getMessage();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{type: string, operator: string, value: string}
     *
     * @throws InvalidAutomationConditionException
     */
    private function condition(array $condition, ?TriggerDefinition $trigger): array
    {
        $type = AutomationConditionType::tryFrom((string) ($condition['type'] ?? ''))
            ?? throw new InvalidAutomationConditionException('Choose a supported condition.');
        $field = ConditionFieldRegistry::get($type);

        if ($trigger !== null && ! $trigger->providesAll($field->requires)) {
            throw new InvalidAutomationConditionException("“{$field->label}” isn’t available when the automation runs on “{$trigger->label}”.");
        }

        $operator = (string) ($condition['operator'] ?? $field->operators()[0]->value);
        $value = AutomationConditionOperator::tryFrom($operator)?->needsValue() === false ? '' : trim((string) ($condition['value'] ?? ''));

        $this->conditions->assertValid($type, $operator, $value);

        return ['type' => $type->value, 'operator' => $operator, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{type: AutomationActionType, configuration: array<string, mixed>, requires_approval: bool}
     *
     * @throws \InvalidArgumentException with a message safe to show
     */
    private function action(array $action, ?TriggerDefinition $trigger, ActionValidation $validation): array
    {
        $definition = ActionRegistry::find(is_string($action['type'] ?? null) ? $action['type'] : null)
            ?? throw new \InvalidArgumentException('Choose a supported action.');
        $config = is_array($action['configuration'] ?? null) ? $action['configuration'] : [];

        if ($trigger !== null) {
            $this->assertActionFits($definition, $config, $trigger);
        }

        $configuration = ($definition->rules)($config, $validation);

        return [
            'type' => $definition->type,
            'configuration' => array_filter($configuration, fn ($value) => $value !== null),
            // Customer emails require approval unless explicitly turned off.
            'requires_approval' => (bool) ($action['requires_approval'] ?? $definition->type->requiresApprovalByDefault()),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertActionFits(ActionDefinition $definition, array $config, TriggerDefinition $trigger): void
    {
        if (! $definition->supports($trigger)) {
            throw new \InvalidArgumentException("“{$definition->label}” can’t be used with “{$trigger->label}” (it would repeat itself).");
        }

        $missing = array_filter($definition->requires($config), fn (Subject $s) => ! $trigger->provides($s));

        if ($missing !== []) {
            throw new \InvalidArgumentException("“{$definition->label}” needs a ".collect($missing)->map(fn (Subject $s) => $s->label())->implode(' and ').", which “{$trigger->label}” doesn’t have.");
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function changeStatus(Automation $automation, User $user, AutomationStatus $status, string $action, array $extra = []): void
    {
        $from = $automation->status;
        $automation->forceFill(['status' => $status, 'updated_by' => $user->id] + $extra)->save();
        AutomationHistory::record($automation, $action, $user, ['from' => $from->value]);
        Log::info("Automation {$action}.", ['organization_id' => $automation->organization_id, 'automation_id' => $automation->id, 'user_id' => $user->id]);
    }
}

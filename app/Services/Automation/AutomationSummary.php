<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\ConversationStatus;
use App\Models\Automation;
use App\Services\Automation\Registry\ActionRegistry;
use App\Services\Automation\Registry\ConditionFieldRegistry;
use App\Services\Automation\Registry\TriggerRegistry;
use Illuminate\Support\Str;

/**
 * An automation in plain English (WHEN / WAIT / IF / THEN), for the builder's review step,
 * the details page and the list. No internal terms.
 */
final class AutomationSummary
{
    /**
     * @param  array<string, mixed>  $input  Builder input (validated or not).
     * @return array{when: ?string, wait: ?string, match: string, conditions: list<string>, actions: list<string>, sentence: string}
     */
    public static function fromInput(array $input): array
    {
        $trigger = TriggerRegistry::find(is_string($input['trigger_type'] ?? null) ? $input['trigger_type'] : null);
        $match = ($input['condition_match'] ?? 'all') === 'any' ? 'any' : 'all';
        $wait = Automation::describeWait(is_numeric($input['wait_minutes'] ?? null) ? (int) $input['wait_minutes'] : null);
        $conditions = array_values(array_filter(array_map(self::condition(...), (array) ($input['conditions'] ?? []))));
        $actions = array_values(array_filter(array_map(self::action(...), (array) ($input['actions'] ?? []))));

        $sentence = $trigger === null ? 'Choose when this automation runs.' : 'This automation will run when '.self::whenPhrase($trigger->label)
            .($wait ? ", wait {$wait}" : '')
            .($conditions === [] ? '' : ($match === 'any' ? ' and continue if any condition is satisfied' : ' and continue if all conditions are satisfied'))
            .'.';

        return ['when' => $trigger?->label, 'wait' => $wait, 'match' => $match, 'conditions' => $conditions, 'actions' => $actions, 'sentence' => $sentence];
    }

    /**
     * @return array{when: ?string, wait: ?string, match: string, conditions: list<string>, actions: list<string>, sentence: string}
     */
    public static function of(Automation $automation): array
    {
        return self::fromInput(app(AutomationBuilder::class)->toInput($automation->loadMissing(['conditions', 'actions'])));
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function condition(mixed $condition): ?string
    {
        if (! is_array($condition) || ! is_string($condition['type'] ?? null)) {
            return null;
        }

        $field = ConditionFieldRegistry::all()[$condition['type']] ?? null;
        $operator = AutomationConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

        if ($field === null || $operator === null) {
            return null;
        }

        return trim("{$field->label} {$operator->label()} ".($operator->needsValue() ? $field->describe($condition['value'] ?? '') : ''));
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private static function action(mixed $action): ?string
    {
        $type = is_array($action) ? ($action['type'] ?? null) : null;
        $type = $type instanceof \BackedEnum ? $type->value : $type;
        $definition = is_string($type) ? ActionRegistry::find($type) : null;

        if ($definition === null) {
            return null;
        }

        $c = (array) ($action['configuration'] ?? []);
        $quote = fn (?string $text) => $text ? ' “'.Str::limit($text, 60).'”' : '';

        return match ($definition->key()) {
            'send_email' => (($c['recipient'] ?? 'customer') === 'owner' ? 'Email me' : 'Send email to the customer').$quote($c['subject'] ?? null),
            'schedule_follow_up' => ($c['kind'] ?? 'email') === 'reminder'
                ? 'Create a follow-up reminder in '.self::days($c['delay_days'] ?? null).$quote($c['title'] ?? null)
                : 'Create a follow-up email in '.self::days($c['delay_days'] ?? null).$quote($c['subject'] ?? null),
            'create_task' => 'Create task'.$quote($c['title'] ?? null).(isset($c['priority']) ? ' ('.ucfirst((string) $c['priority']).' priority)' : ''),
            'notify_user' => 'Notify '.(($c['recipients'] ?? 'admins') === 'members' ? 'everyone' : 'owners and admins').$quote($c['message'] ?? null),
            'add_customer_tag' => 'Tag the customer'.$quote($c['tag'] ?? null),
            'remove_customer_tag' => 'Remove the tag'.$quote($c['tag'] ?? null),
            'update_conversation_status' => 'Set the conversation to “'.(ConversationStatus::tryFrom((string) ($c['status'] ?? ''))?->label() ?? '…').'”',
            default => $definition->label,
        };
    }

    private static function days(mixed $days): string
    {
        $days = (int) $days;

        return $days.' '.($days === 1 ? 'day' : 'days');
    }

    private static function whenPhrase(string $label): string
    {
        return match ($label) {
            'Customer created' => 'a customer is added',
            'Customer reply received' => 'a customer replies',
            'Customer reply received (with AI intent)' => 'a customer replies and the AI has read it',
            'Estimate created' => 'an estimate is created',
            'Estimate sent' => 'an estimate is sent',
            'Estimate viewed' => 'a customer views an estimate',
            'Estimate accepted' => 'a customer accepts an estimate',
            'Estimate declined' => 'a customer declines an estimate',
            'Estimate expired' => 'an estimate expires',
            'Follow-up due' => 'a follow-up is due',
            'Follow-up completed' => 'a follow-up is completed',
            'Conversation closed' => 'a conversation is closed',
            'Conversation reopened' => 'a conversation is reopened',
            default => Str::lower($label),
        };
    }
}

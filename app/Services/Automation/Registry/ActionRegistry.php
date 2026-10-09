<?php

namespace App\Services\Automation\Registry;

use App\Enums\Automation\AutomationActionType as A;
use App\Enums\ConversationStatus;
use App\Enums\TaskPriority;
use App\Services\Automation\Actions\ScheduleFollowUpAction;
use App\Services\Automation\Actions\SendEmailAction;
use App\Services\Automation\Registry\ActionField as Field;

/**
 * Every action an automation can take: label, form fields, what it needs from the trigger,
 * and its validation rules. The builder UI, AutomationBuilder and the engine all use these
 * keys (AutomationActionType values); the UI can't create any other action.
 */
final class ActionRegistry
{
    public const FOLLOW_UP_DELAYS = ['1' => '1 day', '2' => '2 days', '3' => '3 days', '5' => '5 days', '7' => '7 days', '14' => '14 days', '30' => '30 days'];

    public const TASK_DUE = ['' => 'No due date', '4' => 'In 4 hours', '24' => 'In 1 day', '48' => 'In 2 days', '72' => 'In 3 days', '168' => 'In 1 week'];

    /** @var array<string, ActionDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, ActionDefinition>
     */
    public static function all(): array
    {
        $priorities = collect(TaskPriority::cases())->mapWithKeys(fn ($p) => [$p->value => ucfirst($p->value)])->all();
        $statuses = collect(ConversationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
        $none = fn () => [];
        $followUpTriggers = ['follow_up_due', 'follow_up_completed'];

        return self::$definitions ??= collect([
            new ActionDefinition(A::SendEmail, 'Send email', 'Email the customer (in their conversation) or yourself, from your verified business email.', [
                new Field('recipient', 'Send to', 'select', options: ['customer' => 'The customer', 'owner' => 'Me (the automation owner)'], default: 'customer'),
                new Field('subject', 'Subject', 'template', required: true, max: SendEmailAction::MAX_SUBJECT),
                new Field('body', 'Message', 'template_body', required: true, max: SendEmailAction::MAX_BODY),
            ],
                requires: fn (array $c) => ($c['recipient'] ?? 'customer') === 'owner' ? [] : [Subject::Customer, Subject::Conversation],
                rules: fn (array $c, ActionValidation $v) => [
                    'recipient' => ($recipient = $v->choice($c, 'recipient', ['customer', 'owner'], 'customer')) === 'customer' ? null : $recipient,
                    'subject' => $v->template($c, 'subject', SendEmailAction::MAX_SUBJECT, 'Enter an email subject.'),
                    'body' => $v->template($c, 'body', SendEmailAction::MAX_BODY, 'Enter an email message.'),
                ],
                contactsCustomer: true,
            ),
            new ActionDefinition(A::ScheduleFollowUp, 'Create follow-up', 'Schedule a follow-up: a reminder for your team, or an email to the customer when it is due (Task 8 safety rules apply).', [
                new Field('kind', 'Follow-up type', 'select', options: ['email' => 'Email the customer when due', 'reminder' => 'Remind my team when due'], default: 'email'),
                new Field('delay_days', 'Due after', 'select', required: true, options: self::FOLLOW_UP_DELAYS, default: '3'),
                new Field('title', 'Reminder', 'template', required: true, help: 'e.g. Follow up on estimate {{estimate.number}}', showWhen: ['kind' => 'reminder'], max: 255),
                new Field('subject', 'Email subject', 'template', required: true, showWhen: ['kind' => 'email'], max: SendEmailAction::MAX_SUBJECT),
                new Field('body', 'Email message', 'template_body', required: true, showWhen: ['kind' => 'email'], max: SendEmailAction::MAX_BODY),
            ],
                requires: fn (array $c) => ($c['kind'] ?? 'email') === 'reminder' ? [Subject::Customer] : [Subject::Customer, Subject::Conversation],
                rules: fn (array $c, ActionValidation $v) => ($v->choice($c, 'kind', ['email', 'reminder'], 'email') === 'reminder')
                    ? [
                        'kind' => 'reminder',
                        'delay_days' => $v->integer($c, 'delay_days', 1, ScheduleFollowUpAction::MAX_DELAY_DAYS, label: 'Due after (days)'),
                        'title' => $v->template($c, 'title', 255, 'Enter what to follow up on.'),
                    ]
                    : [
                        'delay_days' => $v->integer($c, 'delay_days', 1, ScheduleFollowUpAction::MAX_DELAY_DAYS, label: 'Due after (days)'),
                        'subject' => $v->template($c, 'subject', SendEmailAction::MAX_SUBJECT, 'Enter an email subject.'),
                        'body' => $v->template($c, 'body', SendEmailAction::MAX_BODY, 'Enter an email message.'),
                    ],
                // A follow-up that creates follow-ups would repeat forever.
                excludedTriggers: $followUpTriggers,
                contactsCustomer: true,
            ),
            new ActionDefinition(A::CompleteFollowUp, 'Complete follow-up', 'Mark the open follow-ups for this follow-up, estimate or conversation as completed.', [],
                requires: $none,
                rules: fn () => [],
            ),
            new ActionDefinition(A::CancelFollowUp, 'Cancel follow-up', 'Cancel the open follow-ups for this follow-up, estimate or conversation.', [],
                requires: $none,
                rules: fn () => [],
            ),
            new ActionDefinition(A::CreateTask, 'Create task', 'Add a to-do for your team, linked to the customer and conversation.', [
                new Field('title', 'Task', 'template', required: true, help: 'e.g. Call {{customer.name}} to schedule service', max: 255),
                new Field('description', 'Details', 'textarea', max: 2000),
                new Field('priority', 'Priority', 'select', options: $priorities, default: 'medium'),
                new Field('due_in_hours', 'Due', 'select', options: self::TASK_DUE, default: ''),
                new Field('assign_to', 'Assign to', 'user', default: ''),
            ],
                requires: $none,
                rules: fn (array $c, ActionValidation $v) => [
                    'title' => $v->template($c, 'title', 255, 'Enter a task title.'),
                    'description' => $v->template($c, 'description', 2000, '', required: false),
                    'priority' => $v->choice($c, 'priority', array_keys($priorities), 'medium'),
                    'due_in_hours' => $v->integer($c, 'due_in_hours', 0, 8760, required: false, label: 'Due (hours)'),
                    'assign_to' => $v->user($c, 'assign_to'),
                ],
            ),
            new ActionDefinition(A::NotifyUser, 'Notify my team', 'Show an in-app notification (no email is sent).', [
                new Field('message', 'Message', 'template', required: true, help: 'e.g. {{customer.name}} is ready to book.', max: 500),
                new Field('recipients', 'Notify', 'select', options: ['admins' => 'The business (default recipient, or the owner and managers)', 'members' => 'Everyone on the team'], default: 'admins'),
            ],
                requires: $none,
                rules: fn (array $c, ActionValidation $v) => [
                    'message' => $v->template($c, 'message', 500, 'Enter a notification message.'),
                    'recipients' => is_int($c['recipients'] ?? null) ? $v->user($c, 'recipients') : $v->choice($c, 'recipients', ['admins', 'members'], 'admins'),
                    'channel' => 'in_app',
                ],
            ),
            new ActionDefinition(A::AddCustomerTag, 'Add customer tag', 'Tag the customer, e.g. "Ready to book".', [
                new Field('tag', 'Tag', 'text', required: true, max: 50),
            ],
                requires: fn () => [Subject::Customer],
                rules: fn (array $c, ActionValidation $v) => ['tag' => $v->tag($c)],
            ),
            new ActionDefinition(A::RemoveCustomerTag, 'Remove customer tag', 'Remove a tag from the customer.', [
                new Field('tag', 'Tag', 'text', required: true, max: 50),
            ],
                requires: fn () => [Subject::Customer],
                rules: fn (array $c, ActionValidation $v) => ['tag' => $v->tag($c, mustExist: true)],
            ),
            new ActionDefinition(A::UpdateConversationStatus, 'Change conversation status', 'Set the conversation to open, waiting on the customer, waiting on you, or closed.', [
                new Field('status', 'New status', 'select', required: true, options: $statuses, default: 'waiting_business'),
            ],
                requires: fn () => [Subject::Conversation],
                rules: fn (array $c, ActionValidation $v) => ['status' => $v->choice($c, 'status', array_keys($statuses))],
            ),
        ])->keyBy(fn (ActionDefinition $d) => $d->key())->all();
    }

    public static function get(A|string $type): ActionDefinition
    {
        $key = $type instanceof A ? $type->value : $type;

        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown action [{$key}].");
    }

    public static function find(?string $key): ?ActionDefinition
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /**
     * Actions usable with a trigger.
     *
     * @return list<ActionDefinition>
     */
    public static function for(?TriggerDefinition $trigger): array
    {
        return array_values(array_filter(self::all(), fn (ActionDefinition $a) => $trigger === null || $a->supports($trigger)));
    }
}
